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

use Carbon\Carbon;

/**
 * 商户开通的平台商品，价格按运营商存在 merchant_product_prices。
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $product_id
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MerchantProduct extends Model
{
    protected ?string $table = 'merchant_products';

    protected array $fillable = [
        'merchant_id',
        'product_id',
        'status',
    ];
}
