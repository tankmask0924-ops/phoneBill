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

use App\Model\SupplierProvince;

class SupplierProvinceDao extends AbstractDao
{
    protected string $model = SupplierProvince::class;

    /**
     * @param list<int> $supplierIds
     * @return array<int, list<string>> supplier_id => 省份列表（* 表示全国）
     */
    public function provincesFor(array $supplierIds): array
    {
        $result = [];
        $rows = $this->newQuery()->whereIn('supplier_id', $supplierIds)->orderBy('id')->get(['supplier_id', 'province']);
        foreach ($rows as $row) {
            $result[$row->supplier_id][] = $row->province;
        }

        return $result;
    }

    /**
     * @param list<string> $provinces
     */
    public function replaceForSupplier(int $supplierId, array $provinces): void
    {
        $this->newQuery()->where('supplier_id', $supplierId)->delete();
        foreach ($provinces as $province) {
            $this->create(['supplier_id' => $supplierId, 'province' => $province]);
        }
    }
}
