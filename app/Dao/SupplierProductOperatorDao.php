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

use App\Model\SupplierProductOperator;

class SupplierProductOperatorDao extends AbstractDao
{
    protected string $model = SupplierProductOperator::class;

    /**
     * @param list<int> $supplierProductIds
     * @return array<int, list<string>> supplier_product_id => 运营商编码列表
     */
    public function operatorsFor(array $supplierProductIds): array
    {
        $result = [];
        $rows = $this->newQuery()->whereIn('supplier_product_id', $supplierProductIds)->orderBy('id')->get(['supplier_product_id', 'operator']);
        foreach ($rows as $row) {
            $result[$row->supplier_product_id][] = $row->operator;
        }

        return $result;
    }

    /**
     * @param list<string> $operators
     */
    public function replaceForSupplierProduct(int $supplierProductId, array $operators): void
    {
        $this->newQuery()->where('supplier_product_id', $supplierProductId)->delete();
        foreach ($operators as $operator) {
            $this->create(['supplier_product_id' => $supplierProductId, 'operator' => $operator]);
        }
    }
}
