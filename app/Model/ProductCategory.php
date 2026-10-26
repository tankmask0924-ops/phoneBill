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
 * 商品分类：只给平台商品用，供应商商品不分类。可以限制同一号码在这个分类下的充值次数和同时充值中的单数。
 *
 * @property int $id
 * @property string $name
 * @property int $sort 越小越靠前
 * @property int $mobile_limit_days 号码限制周期（自然日），1 表示当天，0 不限
 * @property int $mobile_limit_orders 周期内同一号码最多几单（所有商户合计、所有状态都算），0 不限
 * @property int $mobile_limit_processing 同一号码同时最多几单在充值中，0 不限
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
        'mobile_limit_days',
        'mobile_limit_orders',
        'mobile_limit_processing',
        'remark',
    ];

    protected array $casts = [
        'sort' => 'integer',
        'mobile_limit_days' => 'integer',
        'mobile_limit_orders' => 'integer',
        'mobile_limit_processing' => 'integer',
    ];
}
