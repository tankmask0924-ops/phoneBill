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
 * 商户后台登录账号，一个商户可以有多个。
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $username
 * @property string $password
 * @property null|string $real_name
 * @property string $status
 * @property null|Carbon $last_login_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MerchantUser extends Model
{
    protected ?string $table = 'merchant_users';

    protected array $fillable = [
        'merchant_id',
        'username',
        'password',
        'real_name',
        'status',
        'last_login_at',
    ];

    protected array $hidden = [
        'password',
    ];

    protected array $dates = [
        'last_login_at',
    ];
}
