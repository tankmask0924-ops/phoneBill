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
 * 系统设置，按 key 存一段 JSON（见 SettingService）。
 *
 * @property int $id
 * @property string $key
 * @property null|string $value
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SystemSetting extends Model
{
    protected ?string $table = 'system_settings';

    protected array $fillable = [
        'key',
        'value',
    ];
}
