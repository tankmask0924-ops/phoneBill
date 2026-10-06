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

use App\Model\ProductDailyStat;
use Hyperf\DbConnection\Db;

class ProductDailyStatDao extends AbstractDao
{
    protected string $model = ProductDailyStat::class;

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
     * 每个商品某一天各运营商合起来的数字。
     *
     * @param list<int> $productIds
     * @return array<int, array{order_count: int, success_count: int, failed_count: int, total_duration: int}>
     */
    public function dayTotals(array $productIds, string $date): array
    {
        if ($productIds === []) {
            return [];
        }
        $rows = $this->newQuery()
            ->where('stat_date', $date)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, sum(order_count) as order_count, sum(success_count) as success_count, sum(failed_count) as failed_count, sum(total_duration) as total_duration')
            ->get();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->product_id] = [
                'order_count' => (int) $row->order_count,
                'success_count' => (int) $row->success_count,
                'failed_count' => (int) $row->failed_count,
                'total_duration' => (int) $row->total_duration,
            ];
        }

        return $result;
    }
}
