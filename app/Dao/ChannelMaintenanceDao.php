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

use App\Model\ChannelMaintenance;

class ChannelMaintenanceDao extends AbstractDao
{
    protected string $model = ChannelMaintenance::class;

    public function find(int $id): ?ChannelMaintenance
    {
        return ChannelMaintenance::find($id);
    }

    /**
     * 还没结束的维护（含还没开始的）。
     *
     * @return list<array{supplier_id: int, operator: null|string, province: null|string, start_at: string, end_at: string}>
     */
    public function notEndedAt(string $now): array
    {
        return $this->newQuery()->where('end_at', '>', $now)->get()
            ->map(static fn (ChannelMaintenance $m) => [
                'supplier_id' => $m->supplier_id,
                'operator' => $m->operator,
                'province' => $m->province,
                'start_at' => $m->start_at->toDateTimeString(),
                'end_at' => $m->end_at->toDateTimeString(),
            ])->values()->all();
    }
}
