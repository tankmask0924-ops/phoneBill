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
 * 平台商品绑定的供应商商品，priority 越小越先用。
 *
 * @property int $id
 * @property int $product_id
 * @property int $supplier_product_id
 * @property int $priority
 */
class ProductRoute extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'product_routes';

    protected array $fillable = [
        'product_id',
        'supplier_product_id',
        'priority',
    ];

    protected array $casts = [
        'priority' => 'integer',
    ];
}
