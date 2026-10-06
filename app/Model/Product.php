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
 * 平台商品：按面值建，一个商品覆盖多个运营商。
 *
 * @property int $id
 * @property string $code 商品编码，商户下单时传
 * @property string $name
 * @property int $face_value 面值（元）
 * @property string $status
 * @property null|string $remark
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Product extends Model
{
    protected ?string $table = 'products';

    protected array $fillable = [
        'code',
        'name',
        'face_value',
        'status',
        'remark',
    ];

    protected array $casts = [
        'face_value' => 'integer',
    ];
}
