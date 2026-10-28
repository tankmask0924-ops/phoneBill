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
 * 按天、省份、运营商、平台商品的订单统计，由 ProvinceProductStatsService 每小时重算当天。
 *
 * @property int $id
 * @property string $stat_date
 * @property string $province
 * @property string $operator
 * @property int $product_id
 * @property int $order_count
 * @property int $success_count
 * @property int $failed_count
 * @property int $unfinished_count
 * @property string $success_amount
 * @property string $profit
 * @property string $computed_at
 */
class ProvinceProductDailyStat extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'province_product_daily_stats';

    protected array $fillable = [
        'stat_date',
        'province',
        'operator',
        'product_id',
        'order_count',
        'success_count',
        'failed_count',
        'unfinished_count',
        'success_amount',
        'profit',
        'computed_at',
    ];

    protected array $casts = [
        'product_id' => 'integer',
        'order_count' => 'integer',
        'success_count' => 'integer',
        'failed_count' => 'integer',
        'unfinished_count' => 'integer',
    ];
}
