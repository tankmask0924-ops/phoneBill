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

use App\Model\SupplierProduct;
use Hyperf\Database\Model\Collection;

class SupplierProductDao extends AbstractDao
{
    protected string $model = SupplierProduct::class;

    public function find(int $id): ?SupplierProduct
    {
        return SupplierProduct::find($id);
    }

    /**
     * 含已删除的，在途的提交和查单时用。
     */
    public function findWithTrashed(int $id): ?SupplierProduct
    {
        return SupplierProduct::withTrashed()->find($id);
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

        return SupplierProduct::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    public function countForSupplier(int $supplierId): int
    {
        return $this->newQuery()->where('supplier_id', $supplierId)->count();
    }

    /**
     * 某个供应商名下的全部供应商商品（不含已删除），按面值、id 排。
     *
     * @return Collection<int, SupplierProduct>
     */
    public function forSupplier(int $supplierId): Collection
    {
        return SupplierProduct::query()->where('supplier_id', $supplierId)->orderBy('face_value')->orderBy('id')->get();
    }

    /**
     * 这些供应商商品编码里，该供应商已经建过的。
     *
     * @param list<string> $codes
     * @return list<string>
     */
    public function existingExternalCodes(int $supplierId, array $codes): array
    {
        $existing = [];
        foreach (array_chunk(array_values(array_unique($codes)), 500) as $chunk) {
            $found = SupplierProduct::query()->where('supplier_id', $supplierId)->whereIn('external_code', $chunk)->distinct()->pluck('external_code')->all();
            array_push($existing, ...$found);
        }

        return $existing;
    }
}
