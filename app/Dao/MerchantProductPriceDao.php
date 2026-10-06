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

use App\Model\MerchantProductPrice;

class MerchantProductPriceDao extends AbstractDao
{
    protected string $model = MerchantProductPrice::class;

    /**
     * @param list<int> $merchantProductIds
     * @return array<int, array<string, string>> merchant_product_id => [运营商编码 => 价格]
     */
    public function pricesFor(array $merchantProductIds): array
    {
        $result = [];
        $rows = $this->newQuery()->whereIn('merchant_product_id', $merchantProductIds)->orderBy('id')->get();
        foreach ($rows as $row) {
            $result[$row->merchant_product_id][$row->operator] = $row->price;
        }

        return $result;
    }

    public function priceFor(int $merchantProductId, string $operator): ?string
    {
        return $this->newQuery()->where('merchant_product_id', $merchantProductId)->where('operator', $operator)->value('price');
    }

    /**
     * @param array<string, string> $prices 运营商编码 => 价格
     */
    public function replaceFor(int $merchantProductId, array $prices): void
    {
        $this->newQuery()->where('merchant_product_id', $merchantProductId)->delete();
        foreach ($prices as $operator => $price) {
            $this->create(['merchant_product_id' => $merchantProductId, 'operator' => $operator, 'price' => $price]);
        }
    }
}
