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

use App\Model\ProductPrice;

class ProductPriceDao extends AbstractDao
{
    protected string $model = ProductPrice::class;

    /**
     * @param list<int> $productIds
     * @return array<int, array<string, string>> product_id => [运营商编码 => 默认售价]
     */
    public function pricesFor(array $productIds): array
    {
        $result = [];
        $rows = $this->newQuery()->whereIn('product_id', $productIds)->orderBy('id')->get(['product_id', 'operator', 'default_price']);
        foreach ($rows as $row) {
            $result[$row->product_id][$row->operator] = $row->default_price;
        }

        return $result;
    }

    /**
     * @param array<string, string> $prices 运营商编码 => 默认售价
     */
    public function replaceForProduct(int $productId, array $prices): void
    {
        $this->newQuery()->where('product_id', $productId)->delete();
        foreach ($prices as $operator => $price) {
            $this->create(['product_id' => $productId, 'operator' => $operator, 'default_price' => $price]);
        }
    }
}
