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
 * 平台商品按天、按运营商的订单统计，由 ProductStatsService 每天凌晨汇总前一天。
 *
 * @property int $id
 * @property string $stat_date
 * @property int $product_id
 * @property string $operator
 * @property int $order_count
 * @property int $success_count
 * @property int $failed_count
 * @property int $unfinished_count
 * @property null|string $success_rate
 * @property int $total_duration
 * @property null|int $avg_duration
 * @property null|int $p50_duration
 * @property null|int $p90_duration
 * @property string $computed_at
 */
class ProductDailyStat extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'product_daily_stats';

    protected array $fillable = [
        'stat_date',
        'product_id',
        'operator',
        'order_count',
        'success_count',
        'failed_count',
        'unfinished_count',
        'success_rate',
        'total_duration',
        'avg_duration',
        'p50_duration',
        'p90_duration',
        'computed_at',
    ];

    protected array $casts = [
        'order_count' => 'integer',
        'success_count' => 'integer',
        'failed_count' => 'integer',
        'unfinished_count' => 'integer',
        'total_duration' => 'integer',
        'avg_duration' => 'integer',
        'p50_duration' => 'integer',
        'p90_duration' => 'integer',
    ];
}
