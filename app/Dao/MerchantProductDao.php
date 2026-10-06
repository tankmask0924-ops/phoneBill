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
}
