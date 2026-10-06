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
 * 号段库：手机号前 7 位 → 运营商、省份、城市。
 *
 * @property int $id
 * @property string $segment
 * @property string $operator
 * @property string $province
 * @property null|string $city
 * @property bool $is_virtual
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MobileSegment extends Model
{
    protected ?string $table = 'mobile_segments';

    protected array $fillable = [
        'segment',
        'operator',
        'province',
        'city',
        'is_virtual',
    ];

    protected array $casts = [
        'is_virtual' => 'boolean',
    ];
}
