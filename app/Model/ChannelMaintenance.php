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
 * 通道维护：start_at ~ end_at 之间，这个供应商（可细到运营商、省份）不参与选路。
 *
 * @property int $id
 * @property int $supplier_id
 * @property null|string $operator 空表示所有运营商
 * @property null|string $province 空表示所有省份
 * @property Carbon $start_at
 * @property Carbon $end_at
 * @property null|string $reason
 * @property null|int $admin_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ChannelMaintenance extends Model
{
    protected ?string $table = 'channel_maintenances';

    protected array $fillable = [
        'supplier_id',
        'operator',
        'province',
        'start_at',
        'end_at',
        'reason',
        'admin_user_id',
    ];

    protected array $dates = [
        'start_at',
        'end_at',
    ];
}
