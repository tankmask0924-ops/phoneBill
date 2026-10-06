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

namespace App\Model;

/**
 * 商户在某个商品、某个运营商上的价格。
 *
 * @property int $id
 * @property int $merchant_product_id
 * @property string $operator
 * @property string $price
 */
class MerchantProductPrice extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'merchant_product_prices';

    protected array $fillable = [
        'merchant_product_id',
        'operator',
        'price',
    ];
}
