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
 * 号码黑名单，命中就拒绝下单。
 *
 * @property int $id
 * @property string $mobile
 * @property null|string $reason
 * @property null|int $admin_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MobileBlacklist extends Model
{
    protected ?string $table = 'mobile_blacklist';

    protected array $fillable = [
        'mobile',
        'reason',
        'admin_user_id',
    ];
}
