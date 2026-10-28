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

namespace App\Service\Admin;

use App\Dao\MerchantDao;
use App\Dao\MerchantFundingRequestDao;
use App\Dao\MerchantPayerAccountDao;
use App\Dao\OrderDao;
use App\Dao\ProductDao;
use App\Dao\ProvinceDailyStatDao;
use App\Dao\ProvinceProductDailyStatDao;
use App\Dao\SupplierDao;
use App\Enum\Operator;
use App\Model\MerchantFundingRequest;
use App\Model\MerchantPayerAccount;
use App\Model\Order;
use App\Service\AbstractService;
use App\Service\Product\ProductStatsService;
use Hyperf\Cache\Annotation\Cacheable;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 管理后台首页汇总：全部订单的累计数据 + 今天的数据。销售额、利润只算成功的订单，按下单时间归属日期。
 * 累计 = 昨天及以前的省份统计（每小时整点重算当天，0～3 点补算前一天）+ 今天实时查的，不扫全部订单。
 * 另外有待办数量（待审核的付款账号、加款申请），实时查，不缓存。
 * 经营统计按日期范围汇总省份统计、省份商品统计两张日表（每小时整点重算当天），不扫订单表。
 */
class DashboardAdminService extends AbstractService
{
    /** 经营统计默认看最近几天（含今天） */
    private const STATS_DEFAULT_DAYS = 7;

    /** 经营统计最多选几天 */
    private const STATS_MAX_DAYS = 92;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantPayerAccountDao $payerDao;

    #[Inject]
    protected MerchantFundingRequestDao $requestDao;

    #[Inject]
    protected ProvinceDailyStatDao $provinceStatDao;

    #[Inject]
    protected ProvinceProductDailyStatDao $provinceProductStatDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected ProductDao $productDao;

    /**
     * 待办：待审核的付款账号、待审核的加款申请。
     *
     * @return array{pending_payer_accounts: int, pending_funding_requests: int}
     */
    public function todos(): array
    {
        return [
            'pending_payer_accounts' => $this->payerDao->newQuery()->where('status', MerchantPayerAccount::STATUS_PENDING)->count(),
            'pending_funding_requests' => $this->requestDao->newQuery()->where('status', MerchantFundingRequest::STATUS_PENDING)->count(),
        ];
    }

    /**
     * 订单待办：异常订单（等人工处理）的数量，实时查。
     *
     * @return array{abnormal_orders: int}
     */
    public function orderTodos(): array
    {
        return [
            'abnormal_orders' => $this->orderDao->newQuery()->where('status', Order::STATUS_ABNORMAL)->count(),
        ];
    }

    /**
     * @return array{total: array<string, mixed>, today: array<string, mixed>}
     */
    public function summary(): array
    {
        $today = $this->orderDao->aggregate(date('Y-m-d 00:00:00'));
        $history = $this->historyTotals(date('Y-m-d'));

        return [
            'total' => [
                'sales' => bcadd($history['success_amount'], $today['sales'], 2),
                'merchant_count' => $this->merchantDao->newQuery()->count(),
                'order_count' => $history['order_count'] + $today['order_count'],
                'profit' => bcadd($history['profit'], $today['profit'], 2),
            ],
            'today' => [
                'order_count' => $today['order_count'],
                'success_count' => $today['success_count'],
                'sales' => $today['sales'],
                'profit' => $today['profit'],
            ],
        ];
    }

    /**
     * 经营统计：一段日期内的合计，以及按日期、供应商、商品、省份分别合计。
     * 日期、供应商、省份取自省份统计，商品取自省份商品统计，两张表同一批订单，合计一致。
     *
     * @param array<string, mixed> $query date_from / date_to（默认最近 7 天，含今天）/ operator
     * @return array<string, mixed>
     */
    public function stats(array $query): array
    {
        $to = $this->dateParam($query['date_to'] ?? null) ?? date('Y-m-d');
        $from = $this->dateParam($query['date_from'] ?? null) ?? date('Y-m-d', strtotime("{$to} -" . (self::STATS_DEFAULT_DAYS - 1) . ' days'));
        if ($from > $to) {
            throw new HttpException(422, '开始日期不能晚于结束日期');
        }
        if ((strtotime($to) - strtotime($from)) / 86400 + 1 > self::STATS_MAX_DAYS) {
            throw new HttpException(422, '日期范围最多 ' . self::STATS_MAX_DAYS . ' 天');
        }
        $operator = in_array($query['operator'] ?? null, Operator::values(), true) ? $query['operator'] : null;

        $summary = $this->provinceStatDao->totalsBetween($from, $to, $operator)[0] ?? null;

        // 没订单的日子也列出来，补 0
        $byDate = [];
        foreach ($this->provinceStatDao->totalsBetween($from, $to, $operator, 'stat_date') as $row) {
            $byDate[(string) $row->stat_date] = $row;
        }
        $days = [];
        for ($day = $to; $day >= $from; $day = date('Y-m-d', strtotime("{$day} -1 day"))) {
            $days[] = ['stat_date' => $day] + self::statTotals($byDate[$day] ?? null);
        }

        $supplierRows = $this->provinceStatDao->totalsBetween($from, $to, $operator, 'supplier_id');
        $supplierNames = $this->supplierDao->namesWithTrashed(array_values(array_filter(array_map(static fn ($r) => (int) $r->supplier_id, $supplierRows))));
        $suppliers = array_map(static fn ($r) => [
            'supplier_id' => (int) $r->supplier_id,
            'supplier_name' => (int) $r->supplier_id === 0 ? null : ($supplierNames[(int) $r->supplier_id] ?? "#{$r->supplier_id}"),
        ] + self::statTotals($r), $supplierRows);

        $productRows = $this->provinceProductStatDao->totalsBetween($from, $to, $operator, 'product_id');
        // 含已删除的商品，历史统计照常显示名字
        $products = $this->productDao->findManyWithTrashed(array_map(static fn ($r) => (int) $r->product_id, $productRows));
        $productItems = array_map(static function ($r) use ($products) {
            $product = $products[(int) $r->product_id] ?? null;

            return [
                'product_id' => (int) $r->product_id,
                'product_code' => $product?->code,
                'product_name' => $product?->name,
                'face_value' => $product?->face_value,
            ] + self::statTotals($r);
        }, $productRows);

        // 省份为空的（号段没查到）并进「未知」
        $provinces = [];
        foreach ($this->provinceStatDao->totalsBetween($from, $to, $operator, 'province') as $r) {
            $province = (string) $r->province === '' ? '未知' : (string) $r->province;
            $provinces[$province] = ['province' => $province] + self::statTotals($r);
        }

        return [
            'date_from' => $from,
            'date_to' => $to,
            'computed_at' => $summary?->computed_at,
            'summary' => self::statTotals($summary),
            'days' => $days,
            'suppliers' => self::byOrderCount($suppliers),
            'products' => self::byOrderCount($productItems),
            'provinces' => self::byOrderCount(array_values($provinces)),
        ];
    }

    /**
     * $today 之前的累计，来自省份统计。历史数据只在整点重算时变，缓存 1 分钟；
     * 缓存键带上日期，过了零点自然换成新的一份。
     *
     * @return array{order_count: int, success_amount: string, profit: string}
     */
    #[Cacheable(prefix: 'admin_dashboard', value: 'history:#{today}', ttl: 60)]
    public function historyTotals(string $today): array
    {
        return $this->provinceStatDao->totalsBefore($today);
    }

    /**
     * 一行合计转成接口返回的数字，$row 为 null（没有数据）时全 0。
     *
     * @return array{order_count: int, success_count: int, failed_count: int, unfinished_count: int, success_rate: null|string, success_amount: string, profit: string}
     */
    private static function statTotals(?object $row): array
    {
        $success = (int) ($row->success_count ?? 0);
        $failed = (int) ($row->failed_count ?? 0);

        return [
            'order_count' => (int) ($row->order_count ?? 0),
            'success_count' => $success,
            'failed_count' => $failed,
            'unfinished_count' => (int) ($row->unfinished_count ?? 0),
            'success_rate' => ProductStatsService::rate($success, $failed),
            'success_amount' => bcadd((string) ($row->success_amount ?? '0'), '0', 2),
            'profit' => bcadd((string) ($row->profit ?? '0'), '0', 2),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function byOrderCount(array $rows): array
    {
        usort($rows, static fn (array $a, array $b) => $b['order_count'] <=> $a['order_count']);

        return $rows;
    }

    private function dateParam(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : null;
    }
}
