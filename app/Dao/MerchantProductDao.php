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

use App\Model\MerchantProduct;

class MerchantProductDao extends AbstractDao
{
    protected string $model = MerchantProduct::class;

    public function findFor(int $merchantId, int $productId): ?MerchantProduct
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->where('product_id', $productId)->first();
    }

    /**
     * 开通着这个平台商品的商户数（不含已关闭的）。
     */
    public function countActiveForProduct(int $productId): int
    {
        return $this->newQuery()->where('product_id', $productId)->where('status', 'active')->count();
    }

    /**
     * 这个平台商品的全部开通记录 id（含已关闭的）。
     *
     * @return list<int>
     */
    public function idsForProduct(int $productId): array
    {
        return $this->newQuery()->where('product_id', $productId)->pluck('id')->map(static fn ($v) => (int) $v)->values()->all();
    }

    public function deleteForProduct(int $productId): void
    {
        $this->newQuery()->where('product_id', $productId)->delete();
    }
}
