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

namespace App\Dao;

use App\Model\ProvinceProductDailyStat;
use Hyperf\Database\Model\Collection;
use Hyperf\DbConnection\Db;

class ProvinceProductDailyStatDao extends AbstractDao
{
    protected string $model = ProvinceProductDailyStat::class;

    /**
     * 整天替换：重算时先删后插，避免留下已经不存在的组合。
     *
     * @param list<array<string, mixed>> $rows
     */
    public function replaceDay(string $date, array $rows): void
    {
        Db::transaction(function () use ($date, $rows) {
            $this->newQuery()->where('stat_date', $date)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                $this->newQuery()->insert($chunk);
            }
        });
    }

    /**
     * 某一天的全部行，可按运营商筛。
     *
     * @return Collection<int, ProvinceProductDailyStat>
     */
    public function forDay(string $date, ?string $operator = null): Collection
    {
        return $this->newQuery()
            ->where('stat_date', $date)
            ->when($operator !== null, static fn ($q) => $q->where('operator', $operator))
            ->get();
    }

    /**
     * 一段日期内的合计，可按运营商筛；$groupBy 为 null 时只有一行总计，否则每组一行、带上 $groupBy 这一列。
     *
     * @return list<object{order_count: int|string, success_count: int|string, failed_count: int|string, unfinished_count: int|string, success_amount: string, profit: string, computed_at: null|string}>
     */
    public function totalsBetween(string $from, string $to, ?string $operator, ?string $groupBy = null): array
    {
        return $this->newQuery()
            ->whereBetween('stat_date', [$from, $to])
            ->when($operator !== null, static fn ($q) => $q->where('operator', $operator))
            ->when($groupBy !== null, static fn ($q) => $q->addSelect($groupBy)->groupBy($groupBy))
            ->selectRaw(
                'sum(order_count) as order_count, sum(success_count) as success_count, sum(failed_count) as failed_count,
                sum(unfinished_count) as unfinished_count, sum(success_amount) as success_amount, sum(profit) as profit, max(computed_at) as computed_at'
            )
            ->toBase()
            ->get()
            ->all();
    }
}
