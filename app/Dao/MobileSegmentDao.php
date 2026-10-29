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

use App\Model\MobileSegment;

class MobileSegmentDao extends AbstractDao
{
    protected string $model = MobileSegment::class;

    public function findBySegment(string $segment): ?MobileSegment
    {
        return $this->newQuery()->where('segment', $segment)->first();
    }

    /**
     * 按号段批量写入，已有的覆盖（导入号段库用）。走查询构造器，不触发模型事件，导入完要整体清缓存。
     *
     * @param list<array<string, mixed>> $rows
     */
    public function upsertMany(array $rows): void
    {
        $this->newQuery()->getQuery()->upsert($rows, ['segment'], ['operator', 'province', 'city', 'is_virtual', 'updated_at']);
    }

    /**
     * 这些号段的城市（号段库原始写法），查不到的号段不在结果里。
     *
     * @param list<string> $segments
     * @return array<string, null|string> 号段 => 城市
     */
    public function citiesFor(array $segments): array
    {
        return $segments === [] ? [] : $this->newQuery()->whereIn('segment', $segments)->pluck('city', 'segment')->all();
    }

    /**
     * 这些号段里库中已有的。
     *
     * @param list<string> $segments
     * @return list<string>
     */
    public function existingSegments(array $segments): array
    {
        return $this->newQuery()->whereIn('segment', $segments)->pluck('segment')->all();
    }

    /**
     * 批量新增，号段已有的跳过（只补新号段用）。同样不触发模型事件，导入完要整体清缓存。
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertMissing(array $rows): void
    {
        $this->newQuery()->getQuery()->insertOrIgnore($rows);
    }
}
