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
 * 平台商品按运营商的默认售价，只用来在给商户开通商品时带出初始价格。
 *
 * @property int $id
 * @property int $product_id
 * @property string $operator
 * @property string $default_price
 */
class ProductPrice extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'product_prices';

    protected array $fillable = [
        'product_id',
        'operator',
        'default_price',
    ];
}
