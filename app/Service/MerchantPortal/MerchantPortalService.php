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
use App\Dao\OrderDao;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Service\AbstractService;
use App\Service\Merchant\MerchantCatalogService;
use App\Service\Order\OrderService;
use Hyperf\Database\Model\Builder;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户后台（只读）：首页、订单记录、资金流水、账户（docs/project.md 5.1）。
 *
 * 所有查询都按调用方传入的 Merchant 过滤（来自登录态，见 MerchantAuthMiddleware）；
 * 不返回成本、供应商等平台内部信息，订单状态按商户视角显示（等待中、异常都是 processing）。
 */
class MerchantPortalService extends AbstractService
{
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
    protected MerchantBalanceLogDao $balanceLogDao;

    #[Inject]
    protected OrderService $orderService;

    #[Inject]
    protected MerchantCatalogService $catalogService;

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
                'processing_count' => $base()->whereIn('status', self::STATUS_FILTER['processing'])->count(),
                'success_count' => $base()->where('status', Order::STATUS_SUCCESS)->count(),
                'failed_count' => $base()->where('status', Order::STATUS_FAILED)->count(),
                'success_amount' => bcadd((string) ($base()->where('status', Order::STATUS_SUCCESS)->sum('sale_price') ?? '0'), '0', 2),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $query keyword（平台 / 商户订单号）/ mobile / status（processing / success / failed）/ created_from / created_to
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function orders(Merchant $merchant, array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));

        $builder = $this->orderDao->newQuery()->where('merchant_id', $merchant->id);
        $keyword = trim((string) ($query['keyword'] ?? ''));
        if ($keyword !== '') {
            $builder->where(fn ($q) => $q->where('order_no', $keyword)->orWhere('merchant_order_no', $keyword));
        }
        $mobile = trim((string) ($query['mobile'] ?? ''));
        if ($mobile !== '') {
            $builder->where('mobile', $mobile);
        }
        if (is_string($query['status'] ?? null) && isset(self::STATUS_FILTER[$query['status']])) {
            $builder->whereIn('status', self::STATUS_FILTER[$query['status']]);
        }
        $this->dateRange($builder, $query);

        $total = (clone $builder)->count();
        $orders = $builder->orderByDesc('id')->forPage($page, $perPage)->get();

        return [
            'data' => $orders->map(fn (Order $o) => $this->presentOrder($o))->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
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

        return $this->presentOrder($order) + [
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
     * @param array<string, mixed> $query type / created_from / created_to
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function balanceLogs(Merchant $merchant, array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));

        $builder = $this->balanceLogDao->newQuery()->where('merchant_id', $merchant->id);
        if (in_array($query['type'] ?? null, self::BALANCE_TYPES, true)) {
            $builder->where('type', $query['type']);
        }
        $this->dateRange($builder, $query);

        $total = (clone $builder)->count();
        $logs = $builder->orderByDesc('id')->forPage($page, $perPage)->get();
        $orders = $this->orderDao->newQuery()
            ->where('merchant_id', $merchant->id)
            ->whereIn('id', $logs->pluck('order_id')->filter()->unique()->all())
            ->get(['id', 'order_no', 'merchant_order_no'])
            ->keyBy('id');

        return [
            'data' => $logs->map(static fn (MerchantBalanceLog $l) => [
                'id' => $l->id,
                'type' => $l->type,
                'amount' => $l->amount,
                'balance_after' => $l->balance_after,
                'order_id' => $l->order_id,
                'order_no' => $l->order_id ? $orders->get($l->order_id)?->order_no : null,
                'merchant_order_no' => $l->order_id ? $orders->get($l->order_id)?->merchant_order_no : null,
                'remark' => $l->remark,
                'created_at' => $l->created_at?->toDateTimeString(),
            ])->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 接入信息：AppKey、通知地址、IP 白名单、能买的商品和价格。AppSecret 不在这里出现。
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
            'products' => $this->catalogService->openedProducts($merchant),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOrder(Order $order): array
    {
        return ['id' => $order->id, 'product_name' => $order->product_name, 'province' => $order->province] + $this->orderService->present($order);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function dateRange(Builder $builder, array $query): void
    {
        foreach (['created_from' => ['>=', ' 00:00:00'], 'created_to' => ['<=', ' 23:59:59']] as $field => [$op, $time]) {
            if (is_string($query[$field] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $query[$field])) {
                $builder->where('created_at', $op, $query[$field] . $time);
            }
        }
    }
}
