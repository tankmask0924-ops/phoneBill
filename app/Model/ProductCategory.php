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
 * 商品分类：只给平台商品用，供应商商品不分类。
 *
 * @property int $id
 * @property string $name
 * @property int $sort 越小越靠前
 * @property null|string $remark
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ProductCategory extends Model
{
    protected ?string $table = 'product_categories';

    protected array $fillable = [
        'name',
        'sort',
        'remark',
    ];

    protected array $casts = [
        'sort' => 'integer',
    ];
}
