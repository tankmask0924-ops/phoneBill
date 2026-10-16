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

use App\Dao\ProductDailyStatDao;
use App\Dao\ProductDao;
use App\Dao\ProvinceDailyStatDao;
use App\Dao\SupplierDao;
use App\Enum\Operator;
use App\Model\ProductDailyStat;
use App\Model\ProvinceDailyStat;
use App\Service\AbstractService;
use App\Service\Product\ProductStatsService;
use Hyperf\Database\Model\Builder;
use Hyperf\Di\Annotation\Inject;

/**
 * 商品中心 - 商品统计（只读）：商品日统计由 ProductStatsService 每天汇总，省份统计由 ProvinceStatsService 每小时重算当天。
 */
class ProductStatsAdminService extends AbstractService
{
    private const MAX_PER_PAGE = 100;

    /** 没传日期时默认看最近几天（到昨天为止，今天的还没汇总） */
    private const DEFAULT_DAYS = 7;

    #[Inject]
    protected ProductDailyStatDao $statDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected ProvinceDailyStatDao $provinceStatDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    /**
     * @param array<string, mixed> $query date_from / date_to / product_id / keyword / operator
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, summary: array<string, mixed>}
     */
    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));

        $builder = $this->statDao->newQuery();
        $this->applyFilters($builder, $query);

        $total = (clone $builder)->count();
        $summary = (clone $builder)
            ->selectRaw('sum(order_count) as order_count, sum(success_count) as success_count, sum(failed_count) as failed_count, sum(unfinished_count) as unfinished_count, sum(total_duration) as total_duration')
            ->toBase()
            ->first();
        $stats = $builder->orderByDesc('stat_date')->orderBy('product_id')->orderBy('operator')->forPage($page, $perPage)->get();
        // 含已删除的商品，历史统计照常显示名字
        $products = $this->productDao->findManyWithTrashed($stats->pluck('product_id')->unique()->values()->all());

        return [
            'data' => $stats->map(function (ProductDailyStat $s) use ($products) {
                $product = $products[$s->product_id] ?? null;

                return [
                    'stat_date' => $s->stat_date,
                    'product_id' => $s->product_id,
                    'product_code' => $product?->code,
                    'product_name' => $product?->name,
                    'face_value' => $product?->face_value,
                    'operator' => $s->operator,
                    'order_count' => $s->order_count,
                    'success_count' => $s->success_count,
                    'failed_count' => $s->failed_count,
                    'unfinished_count' => $s->unfinished_count,
                    'success_rate' => $s->success_rate,
                    'avg_duration' => $s->avg_duration,
                    'p50_duration' => $s->p50_duration,
                    'p90_duration' => $s->p90_duration,
                    'computed_at' => $s->computed_at,
                ];
            })->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'summary' => self::summarize(
                (int) ($summary->order_count ?? 0),
                (int) ($summary->success_count ?? 0),
                (int) ($summary->failed_count ?? 0),
                (int) ($summary->total_duration ?? 0),
            ) + ['unfinished_count' => (int) ($summary->unfinished_count ?? 0)],
        ];
    }

    /**
     * 某一天的省份统计：每个省份的合计，展开是这个省份下各供应商的数字。
     *
     * @param array<string, mixed> $query date（默认今天）/ operator
     * @return array{stat_date: string, computed_at: null|string, summary: array<string, mixed>, provinces: list<array<string, mixed>>}
     */
    public function provinces(array $query): array
    {
        $date = $this->dateParam($query['date'] ?? null) ?? date('Y-m-d');
        $operator = in_array($query['operator'] ?? null, Operator::values(), true) ? $query['operator'] : null;
        $stats = $this->provinceStatDao->forDay($date, $operator);
        $suppliers = $this->supplierDao->namesWithTrashed($stats->pluck('supplier_id')->filter()->unique()->values()->all());

        $summary = null;
        $provinces = [];
        $bySupplier = [];
        foreach ($stats as $s) {
            $summary = self::addUp($summary, $s);
            $province = $s->province === '' ? '未知' : $s->province;
            $provinces[$province] = self::addUp($provinces[$province] ?? null, $s);
            $bySupplier[$province][$s->supplier_id] = self::addUp($bySupplier[$province][$s->supplier_id] ?? null, $s);
        }
        $rows = [];
        foreach ($provinces as $province => $totals) {
            $supplierRows = [];
            foreach ($bySupplier[$province] as $supplierId => $supplierTotals) {
                $supplierRows[] = [
                    'supplier_id' => $supplierId,
                    'supplier_name' => $supplierId === 0 ? null : ($suppliers[$supplierId] ?? "#{$supplierId}"),
                ] + self::withRate($supplierTotals);
            }
            usort($supplierRows, static fn (array $a, array $b) => $b['order_count'] <=> $a['order_count']);
            $rows[] = ['province' => $province] + self::withRate($totals) + ['suppliers' => $supplierRows];
        }
        usort($rows, static fn (array $a, array $b) => $b['order_count'] <=> $a['order_count']);

        return [
            'stat_date' => $date,
            'computed_at' => $stats->max('computed_at'),
            'summary' => self::withRate($summary ?? self::addUp(null, null)),
            'provinces' => $rows,
        ];
    }

    /**
     * 多行合起来的成功率和平均耗时（按成功单数加权）。
     *
     * @return array{order_count: int, success_count: int, failed_count: int, success_rate: null|string, avg_duration: null|int}
     */
    public static function summarize(int $orders, int $success, int $failed, int $totalDuration): array
    {
        return [
            'order_count' => $orders,
            'success_count' => $success,
            'failed_count' => $failed,
            'success_rate' => ProductStatsService::rate($success, $failed),
            'avg_duration' => $success === 0 ? null : (int) round($totalDuration / $success),
        ];
    }

    /**
     * 累加一行省份统计，$row 为 null 时返回全 0。
     *
     * @param null|array{order_count: int, success_count: int, failed_count: int, unfinished_count: int, success_amount: string, profit: string} $totals
     * @return array{order_count: int, success_count: int, failed_count: int, unfinished_count: int, success_amount: string, profit: string}
     */
    private static function addUp(?array $totals, ?ProvinceDailyStat $row): array
    {
        $totals ??= ['order_count' => 0, 'success_count' => 0, 'failed_count' => 0, 'unfinished_count' => 0, 'success_amount' => '0.00', 'profit' => '0.00'];
        if ($row === null) {
            return $totals;
        }

        return [
            'order_count' => $totals['order_count'] + $row->order_count,
            'success_count' => $totals['success_count'] + $row->success_count,
            'failed_count' => $totals['failed_count'] + $row->failed_count,
            'unfinished_count' => $totals['unfinished_count'] + $row->unfinished_count,
            'success_amount' => bcadd($totals['success_amount'], (string) $row->success_amount, 2),
            'profit' => bcadd($totals['profit'], (string) $row->profit, 2),
        ];
    }

    /**
     * @param array{order_count: int, success_count: int, failed_count: int, unfinished_count: int, success_amount: string, profit: string} $totals
     * @return array<string, mixed>
     */
    private static function withRate(array $totals): array
    {
        return $totals + ['success_rate' => ProductStatsService::rate($totals['success_count'], $totals['failed_count'])];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function applyFilters(Builder $builder, array $query): void
    {
        $from = $this->dateParam($query['date_from'] ?? null) ?? date('Y-m-d', strtotime('-' . self::DEFAULT_DAYS . ' days'));
        $to = $this->dateParam($query['date_to'] ?? null);
        $builder->where('stat_date', '>=', $from);
        if ($to !== null) {
            $builder->where('stat_date', '<=', $to);
        }
        if (isset($query['product_id']) && is_numeric($query['product_id'])) {
            $builder->where('product_id', (int) $query['product_id']);
        }
        $keyword = trim((string) ($query['keyword'] ?? ''));
        if ($keyword !== '') {
            $builder->whereIn('product_id', $this->productDao->idsMatchingWithTrashed($keyword));
        }
        if (in_array($query['operator'] ?? null, Operator::values(), true)) {
            $builder->where('operator', $query['operator']);
        }
    }

    private function dateParam(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
