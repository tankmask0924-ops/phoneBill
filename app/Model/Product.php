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
use Hyperf\Database\Model\SoftDeletes;

/**
 * 平台商品：按面值建，一个商品覆盖多个运营商。
 *
 * @property int $id
 * @property null|int $category_id 商品分类，老商品可能没有
 * @property string $code 商品编码，商户下单时传
 * @property string $name
 * @property int $face_value 面值（元）
 * @property string $status
 * @property null|string $remark
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property null|Carbon $deleted_at
 */
class Product extends Model
{
    use SoftDeletes;

    protected ?string $table = 'products';

    protected array $fillable = [
        'category_id',
        'code',
        'name',
        'face_value',
        'status',
        'remark',
    ];

    protected array $casts = [
        'category_id' => 'integer',
        'face_value' => 'integer',
    ];
}
