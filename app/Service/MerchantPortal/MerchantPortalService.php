<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Service\MerchantPortal;

use App\Dao\MerchantBalanceLogDao;
use App\Dao\OrderAttemptDao;
use App\Dao\OrderDao;
use App\Dao\ProductCategoryDao;
use App\Dao\ProductDao;
use App\Enum\Operator;
use App\Enum\Province;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Service\AbstractService;
use App\Service\FiltersByDateRange;
use App\Service\FiltersByMobiles;
use App\Service\Merchant\MerchantCatalogService;
use App\Service\Order\OrderService;
use App\Service\Product\ProductStatsService;
use Hyperf\Database\Model\Builder;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户后台（只读）：首页、订单记录、资金流水、账户（docs/project.md 5.1）。
 *
 * 所有查询都按调用方传入的 Merchant 过滤（来自登录态，见 MerchantAuthMiddleware）；
 * 不返回成本、供应商等平台内部信息，订单状态按商户视角显示（等待中是 processing），异常的显示为 abnormal（见 presentOrders()）。
 * 异常只在商户后台显示：开放接口查单、回调商户仍然是 processing（见 OrderService::present()）。
 */
class MerchantPortalService extends AbstractService
{
    use FiltersByDateRange;
    use FiltersByMobiles;

    private const MAX_PER_PAGE = 100;

    /** 商户看到的状态 → 实际的订单状态 */
    private const STATUS_FILTER = [
        'processing' => [Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL],
        'success' => [Order::STATUS_SUCCESS],
        'failed' => [Order::STATUS_FAILED],
    ];

    /** 商户看到的流水类型：人工加款 / 扣款不暴露操作人 */
    private const BALANCE_TYPES = [
        MerchantBalanceLog::TYPE_RECHARGE,
        MerchantBalanceLog::TYPE_DEDUCT,
        MerchantBalanceLog::TYPE_ORDER_PAY,
        MerchantBalanceLog::TYPE_ORDER_REFUND,
    ];

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderAttemptDao $attemptDao;

    #[Inject]
    protected MerchantBalanceLogDao $balanceLogDao;

    #[Inject]
    protected OrderService $orderService;

    #[Inject]
    protected MerchantCatalogService $catalogService;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected ProductCategoryDao $categoryDao;

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Merchant $merchant): array
    {
        $today = date('Y-m-d 00:00:00');
        $base = fn () => $this->orderDao->newQuery()->where('merchant_id', $merchant->id)->where('created_at', '>=', $today);

        return [
            'balance' => $merchant->balance,
            'merchant_status' => $merchant->status,
            'today' => [
                'order_count' => $base()->count(),
                'processing_count' => $this->whereMerchantStatus($base(), 'processing')->count(),
                'success_count' => $this->whereMerchantStatus($base(), 'success')->count(),
                'failed_count' => $this->whereMerchantStatus($base(), 'failed')->count(),
                'success_amount' => bcadd((string) ($this->whereMerchantStatus($base(), 'success')->sum('sale_price') ?? '0'), '0', 2),
            ],
        ];
    }

    /**
     * summary 是当前筛选条件下全部订单（不只这一页）的汇总，见 orderSummary()。
     *
     * @param array<string, mixed> $query 见 filteredOrders()
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, summary: array<string, mixed>}
     */
    public function orders(Merchant $merchant, array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));

        $builder = $this->filteredOrders($merchant, $query);
        $summary = $this->orderSummary($builder);
        $orders = $builder->orderByDesc('id')->forPage($page, $perPage)->get();

        return [
            'data' => $this->presentOrders($orders->all()),
            'total' => $summary['order_count'],
            'page' => $page,
            'per_page' => $perPage,
            'summary' => $summary,
        ];
    }

    /**
     * 订单列表、导出共用的筛选。没传日期、也不是按单号 / 手机号查时，默认最近 7 天。
     *
     * @param array<string, mixed> $query keyword（平台 / 商户订单号）/ mobile（可多个，见 FiltersByMobiles）/ status（processing / success / failed）/ operator / province / face_value / category_id / created_from / created_to
     */
    public function filteredOrders(Merchant $merchant, array $query): Builder
    {
        $builder = $this->orderDao->newQuery()->where('merchant_id', $merchant->id);
        $keyword = trim((string) ($query['keyword'] ?? ''));
        if ($keyword !== '') {
            $builder->where(fn ($q) => $q->where('order_no', $keyword)->orWhere('merchant_order_no', $keyword));
        }
        $byMobile = $this->applyMobileFilter($builder, $query['mobile'] ?? null);
        if (is_string($query['status'] ?? null) && isset(self::STATUS_FILTER[$query['status']])) {
            $this->whereMerchantStatus($builder, $query['status']);
        }
        if (is_string($query['operator'] ?? null) && Operator::tryFrom($query['operator']) !== null) {
            $builder->where('operator', $query['operator']);
        }
        if (is_string($query['province'] ?? null) && Province::isValid($query['province'])) {
            $builder->where('province', $query['province']);
        }
        if (is_numeric($query['face_value'] ?? null)) {
            $builder->where('face_value', (int) $query['face_value']);
        }
        if (is_numeric($query['category_id'] ?? null)) {
            $builder->whereIn('product_id', $this->productDao->idsByCategory((int) $query['category_id']));
        }
        $this->applyDateRange($builder, $query, $keyword !== '' || $byMobile);

        return $builder;
    }

    /**
     * @return array<string, mixed>
     */
    public function order(Merchant $merchant, int $id): array
    {
        $order = $this->orderDao->find($id);
        if ($order === null || $order->merchant_id !== $merchant->id) {
            throw new HttpException(404, '订单不存在');
        }

        return $this->presentOrders([$order])[0] + [
            'balance_logs' => $this->balanceLogDao->newQuery()->where('merchant_id', $merchant->id)->where('order_id', $order->id)->orderBy('id')->get()
                ->map(static fn (MerchantBalanceLog $l) => [
                    'type' => $l->type,
                    'amount' => $l->amount,
                    'balance_after' => $l->balance_after,
                    'created_at' => $l->created_at?->toDateTimeString(),
                ])->values()->all(),
        ];
    }

    /**
     * order_no 填平台订单号或商户订单号都行，按订单号查时不默认限最近几天。
     *
     * @param array<string, mixed> $query type / order_no / created_from / created_to
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function balanceLogs(Merchant $merchant, array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));

        $builder = $this->filteredBalanceLogs($merchant, $query);
        $total = (clone $builder)->count();
        $logs = $builder->orderByDesc('id')->forPage($page, $perPage)->get();

        return [
            'data' => $this->presentBalanceLogs($merchant, $logs->all()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 资金流水列表、导出共用的筛选。
     *
     * @param array<string, mixed> $query type / order_no / created_from / created_to
     */
    public function filteredBalanceLogs(Merchant $merchant, array $query): Builder
    {
        $builder = $this->balanceLogDao->newQuery()->where('merchant_id', $merchant->id);
        if (in_array($query['type'] ?? null, self::BALANCE_TYPES, true)) {
            $builder->where('type', $query['type']);
        }
        $orderNo = trim(is_string($query['order_no'] ?? null) ? $query['order_no'] : '');
        if ($orderNo !== '') {
            $builder->whereIn('order_id', $this->orderDao->idsForMerchantByNo($merchant->id, $orderNo));
        }
        $this->applyDateRange($builder, $query, $orderNo !== '');

        return $builder;
    }

    /**
     * 补上订单号、手机号（一页的订单一次查出）。
     *
     * @param list<MerchantBalanceLog> $logs
     * @return list<array<string, mixed>>
     */
    public function presentBalanceLogs(Merchant $merchant, array $logs): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map(static fn (MerchantBalanceLog $l) => $l->order_id, $logs))));
        $orders = $orderIds === [] ? [] : $this->orderDao->newQuery()
            ->where('merchant_id', $merchant->id)
            ->whereIn('id', $orderIds)
            ->get(['id', 'order_no', 'merchant_order_no', 'mobile'])
            ->keyBy('id')
            ->all();

        return array_map(static function (MerchantBalanceLog $l) use ($orders) {
            $order = $l->order_id ? ($orders[$l->order_id] ?? null) : null;

            return [
                'id' => $l->id,
                'type' => $l->type,
                'amount' => $l->amount,
                'balance_after' => $l->balance_after,
                'order_id' => $l->order_id,
                'order_no' => $order?->order_no,
                'merchant_order_no' => $order?->merchant_order_no,
                'mobile' => $order?->mobile,
                'remark' => $l->remark,
                'created_at' => $l->created_at?->toDateTimeString(),
            ];
        }, $logs);
    }

    /**
     * 接入信息：AppKey、通知地址、IP 白名单（能买的商品见 products()）。AppSecret 不在这里出现。
     *
     * @return array<string, mixed>
     */
    public function account(Merchant $merchant): array
    {
        return [
            'name' => $merchant->name,
            'status' => $merchant->status,
            'app_key' => $merchant->app_key,
            'notify_url' => $merchant->notify_url,
            'ip_whitelist' => $merchant->ipWhitelist(),
        ];
    }

    /**
     * 订单筛选的选项：省份（运营商前端写死），已开通商品的面值和分类。
     *
     * @return array{provinces: list<string>, face_values: list<int>, categories: list<array{id: int, name: string}>}
     */
    public function orderFilterOptions(Merchant $merchant): array
    {
        $products = $this->catalogService->openedProductModels($merchant);
        $faceValues = array_values(array_unique($products->pluck('face_value')->map(static fn ($v) => (int) $v)->all()));
        sort($faceValues);

        return [
            'provinces' => Province::NAMES,
            'face_values' => $faceValues,
            'categories' => $this->categoryDao->optionsFor($products->pluck('category_id')->filter()->unique()->values()->all()),
        ];
    }

    /**
     * 可下单商品：价格 + 平台整体近 7 天的成功率、平均耗时。
     *
     * @return array{from: string, to: string, products: list<array<string, mixed>>, categories: list<array{id: int, name: string}>}
     */
    public function products(Merchant $merchant): array
    {
        return $this->catalogService->openedProductsWithStats($merchant);
    }

    /**
     * 商户后台（列表、详情、导出）看到的订单：平台这边异常、商户这边还没出结果的，状态显示为 abnormal（见 Order::abnormalForMerchant()）。
     * 只用于商户后台；开放接口查单、回调用 OrderService::present()，异常仍然是 processing。
     *
     * @param list<Order> $orders
     * @return list<array<string, mixed>>
     */
    public function presentOrders(array $orders): array
    {
        $unclear = $this->attemptDao->unclearProcessing(array_map(static fn (Order $o) => $o->id, $orders));

        return array_map(function (Order $o) use ($unclear) {
            $presented = ['id' => $o->id, 'product_name' => $o->product_name, 'province' => $o->province] + $this->orderService->present($o);
            if ($o->abnormalForMerchant(isset($unclear[$o->id]))) {
                $presented['status'] = 'abnormal';
            }

            return $presented;
        }, $orders);
    }

    /**
     * 筛选条件下全部订单的汇总：成功率 = 成功 /（成功 + 失败），消费金额和平均耗时只算成功的订单。
     * 成功、失败按商户看到的状态算：人工处理单手动回调之前算处理中（和 whereMerchantStatus() 一致）。
     *
     * @return array{order_count: int, success_count: int, failed_count: int, processing_count: int, success_rate: null|string, amount: string, avg_duration: null|int}
     */
    private function orderSummary(Builder $builder): array
    {
        $visible = '(manual_only = 0 or manual_notified_at is not null)';
        $row = (clone $builder)->selectRaw(
            "count(*) as order_count,
            coalesce(sum(status = 'success' and {$visible}), 0) as success_count,
            coalesce(sum(status = 'failed' and {$visible}), 0) as failed_count,
            coalesce(sum(case when status = 'success' and {$visible} then sale_price end), 0) as amount,
            avg(case when status = 'success' and {$visible} and finished_at is not null then timestampdiff(second, created_at, finished_at) end) as avg_duration"
        )->toBase()->first();
        $orders = (int) $row->order_count;
        $success = (int) $row->success_count;
        $failed = (int) $row->failed_count;

        return [
            'order_count' => $orders,
            'success_count' => $success,
            'failed_count' => $failed,
            'processing_count' => $orders - $success - $failed,
            'success_rate' => ProductStatsService::rate($success, $failed),
            'amount' => bcadd((string) $row->amount, '0', 2),
            'avg_duration' => $row->avg_duration === null ? null : (int) round((float) $row->avg_duration),
        ];
    }

    /**
     * 按商户看到的状态筛选，和 Order::resultVisibleToMerchant() 一致：人工处理单手动回调之前算处理中。
     */
    private function whereMerchantStatus(Builder $builder, string $status): Builder
    {
        $hidden = static fn ($q) => $q->where('manual_only', true)->whereNull('manual_notified_at');
        if ($status === 'processing') {
            return $builder->where(static fn ($q) => $q->whereIn('status', self::STATUS_FILTER['processing'])
                ->orWhere(static fn ($q) => $hidden($q->whereIn('status', [Order::STATUS_SUCCESS, Order::STATUS_FAILED]))));
        }

        return $builder->whereIn('status', self::STATUS_FILTER[$status])
            ->where(static fn ($q) => $q->where('manual_only', false)->orWhereNotNull('manual_notified_at'));
    }
}
