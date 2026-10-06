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
 * 供应商商品。
 *
 * @property int $id
 * @property int $supplier_id
 * @property string $name
 * @property int $face_value 面值（元）
 * @property string $cost_price 成本价（元）
 * @property string $external_code 供应商侧商品编码
 * @property string $status
 * @property null|string $remark
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SupplierProduct extends Model
{
    protected ?string $table = 'supplier_products';

    protected array $fillable = [
        'supplier_id',
        'name',
        'face_value',
        'cost_price',
        'external_code',
        'status',
        'remark',
    ];

    protected array $casts = [
        'face_value' => 'integer',
    ];
}
