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

class SupplierProductDao extends AbstractDao
{
    protected string $model = SupplierProduct::class;

    public function find(int $id): ?SupplierProduct
    {
        return SupplierProduct::find($id);
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
