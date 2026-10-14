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

use App\Model\Supplier;

class SupplierDao extends AbstractDao
{
    protected string $model = Supplier::class;

    public function find(int $id): ?Supplier
    {
        return Supplier::find($id);
    }

    /**
     * 含已删除的，查历史订单、在途的提交和回调时用。
     */
    public function findWithTrashed(int $id): ?Supplier
    {
        return Supplier::withTrashed()->find($id);
    }

    public function findByCodeWithTrashed(string $code): ?Supplier
    {
        return Supplier::withTrashed()->where('code', $code)->first();
    }

    /**
     * 全部供应商（含已删除的），按 id 排。
     *
     * @return list<array{id: int, name: string, deleted: bool}>
     */
    public function allWithTrashed(): array
    {
        return Supplier::withTrashed()->orderBy('id')->get(['id', 'name', 'deleted_at'])
            ->map(static fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name, 'deleted' => $s->deleted_at !== null])
            ->values()->all();
    }

    /**
     * id => 名称，含已删除的（给历史数据显示名字）。
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function namesWithTrashed(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Supplier::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
