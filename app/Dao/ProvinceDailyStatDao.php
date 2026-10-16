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

use App\Model\ProvinceDailyStat;
use Hyperf\Database\Model\Collection;
use Hyperf\DbConnection\Db;

class ProvinceDailyStatDao extends AbstractDao
{
    protected string $model = ProvinceDailyStat::class;

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
     * @return Collection<int, ProvinceDailyStat>
     */
    public function forDay(string $date, ?string $operator = null): Collection
    {
        return $this->newQuery()
            ->where('stat_date', $date)
            ->when($operator !== null, static fn ($q) => $q->where('operator', $operator))
            ->get();
    }
}
