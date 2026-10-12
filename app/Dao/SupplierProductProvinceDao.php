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

use App\Model\SupplierProductProvince;

class SupplierProductProvinceDao extends AbstractDao
{
    protected string $model = SupplierProductProvince::class;

    /**
     * @param list<int> $supplierProductIds
     * @return array<int, list<string>> supplier_product_id => 省份列表（* 表示全国）
     */
    public function provincesFor(array $supplierProductIds): array
    {
        $result = [];
        if ($supplierProductIds === []) {
            return $result;
        }
        $rows = $this->newQuery()->whereIn('supplier_product_id', $supplierProductIds)->orderBy('id')->get(['supplier_product_id', 'province']);
        foreach ($rows as $row) {
            $result[$row->supplier_product_id][] = $row->province;
        }

        return $result;
    }

    /**
     * @param list<string> $provinces
     */
    public function replaceForSupplierProduct(int $supplierProductId, array $provinces): void
    {
        $this->newQuery()->where('supplier_product_id', $supplierProductId)->delete();
        foreach ($provinces as $province) {
            $this->create(['supplier_product_id' => $supplierProductId, 'province' => $province]);
        }
    }
}
