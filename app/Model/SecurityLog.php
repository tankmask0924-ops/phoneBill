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
 * 登录与账号安全日志 `security_logs`：两端的登录成功 / 失败、改密码、商户自助重置密钥 / 改 IP 白名单 / 批量下单等，
 * 用来排查账号被盗用。只写不改，所以关掉 updated_at 自动维护，created_at 由写入方给。
 *
 * @property int $id
 * @property string $portal
 * @property string $event
 * @property null|int $user_id
 * @property string $username
 * @property null|int $merchant_id
 * @property null|string $ip
 * @property null|string $user_agent
 * @property null|array $detail
 * @property Carbon $created_at
 */
class SecurityLog extends Model
{
    public const PORTAL_ADMIN = 'admin';

    public const PORTAL_MERCHANT = 'merchant';

    public const EVENT_LOGIN_SUCCESS = 'login_success';

    public const EVENT_LOGIN_FAILED = 'login_failed';

    /** 密码对了、已发登录短信验证码，等第二步 */
    public const EVENT_LOGIN_SMS_SENT = 'login_sms_sent';

    public const EVENT_CHANGE_PASSWORD = 'change_password';

    public const EVENT_RESET_SECRET = 'reset_secret';

    public const EVENT_UPDATE_IP_WHITELIST = 'update_ip_whitelist';

    public const EVENT_BIND_MOBILE = 'bind_mobile';

    public const EVENT_SET_SMS_LOGIN = 'set_sms_login';

    /** 商户后台批量下单 */
    public const EVENT_BATCH_ORDER = 'batch_order';

    /** 登录失败原因，写在 detail.reason */
    public const REASON_UNKNOWN_USER = 'unknown_user';

    public const REASON_WRONG_PASSWORD = 'wrong_password';

    public const REASON_DISABLED = 'disabled';

    public const REASON_SMS_CODE = 'sms_code';

    public bool $timestamps = false;

    protected ?string $table = 'security_logs';

    protected array $fillable = [
        'portal',
        'event',
        'user_id',
        'username',
        'merchant_id',
        'ip',
        'user_agent',
        'detail',
        'created_at',
    ];

    protected array $casts = [
        'detail' => 'array',
    ];

    protected array $dates = [
        'created_at',
    ];
}
