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
 * 按天、省份、市、运营商的订单统计，由 ProvinceStatsService 和省份统计一起每小时重算当天。
 *
 * @property int $id
 * @property string $stat_date
 * @property string $province
 * @property string $city 空 = 订单没有记市
 * @property string $operator
 * @property int $order_count
 * @property int $success_count
 * @property int $failed_count
 * @property int $unfinished_count
 * @property string $success_amount
 * @property string $profit
 * @property string $computed_at
 */
class CityDailyStat extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'city_daily_stats';

    protected array $fillable = [
        'stat_date',
        'province',
        'city',
        'operator',
        'order_count',
        'success_count',
        'failed_count',
        'unfinished_count',
        'success_amount',
        'profit',
        'computed_at',
    ];

    protected array $casts = [
        'order_count' => 'integer',
        'success_count' => 'integer',
        'failed_count' => 'integer',
        'unfinished_count' => 'integer',
    ];
}
