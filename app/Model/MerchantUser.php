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
use Hyperf\Database\Model\SoftDeletes;

/**
 * 商户后台登录账号，一个商户可以有多个。
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $username
 * @property string $password
 * @property null|string $real_name
 * @property null|string $mobile 绑定的手机号（余额提醒发到这里）
 * @property bool $sms_login_enabled 登录时是否要短信验证码，账号自己在商户后台开关，要先绑定手机
 * @property string $status
 * @property int $login_failures
 * @property null|Carbon $last_login_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property null|Carbon $deleted_at 软删除时间，删除后账号名仍然占用
 */
class MerchantUser extends Model
{
    use SoftDeletes;

    protected ?string $table = 'merchant_users';

    protected array $fillable = [
        'merchant_id',
        'username',
        'password',
        'real_name',
        'mobile',
        'sms_login_enabled',
        'status',
        'login_failures',
        'last_login_at',
    ];

    protected array $hidden = [
        'password',
    ];

    protected array $casts = [
        'login_failures' => 'integer',
        'sms_login_enabled' => 'boolean',
    ];

    protected array $dates = [
        'last_login_at',
    ];
}
