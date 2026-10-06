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

namespace App\Service\MerchantPortal;

use App\Auth\MerchantJwtGuard;
use App\Dao\MerchantUserDao;
use App\Model\Merchant;
use App\Model\MerchantUser;
use App\Service\AbstractService;
use Carbon\Carbon;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Redis\Redis;

/**
 * 商户后台账户：登录、当前账号信息、修改自己的密码。
 *
 * 商户后台放在外网，登录加了失败次数限制：同一账号 15 分钟内失败 10 次就暂时锁住。
 */
class MerchantAuthService extends AbstractService
{
    private const GENERIC_LOGIN_FAIL_MESSAGE = '账号或密码错误';

    private const MAX_FAILURES = 10;

    private const FAILURE_WINDOW_SECONDS = 900;

    #[Inject]
    protected MerchantUserDao $merchantUserDao;

    #[Inject]
    protected MerchantJwtGuard $tokenGuard;

    #[Inject]
    protected Redis $redis;

    /**
     * @return array{token: string, username: string}
     */
    public function login(string $username, string $password): array
    {
        $username = trim($username);
        $failKey = 'merchant_login_fail:' . md5(strtolower($username));
        if ((int) $this->redis->get($failKey) >= self::MAX_FAILURES) {
            throw new HttpException(429, '登录失败次数太多，请 15 分钟后再试');
        }

        $user = $username === '' ? null : $this->merchantUserDao->findByUsername($username);
        if (! $user || ! password_verify($password, $user->password)) {
            $this->redis->incr($failKey);
            $this->redis->expire($failKey, self::FAILURE_WINDOW_SECONDS);
            // 账号不存在 / 密码错误共用同一条消息，防止枚举出哪些账号存在
            throw new HttpException(401, self::GENERIC_LOGIN_FAIL_MESSAGE);
        }
        if ($user->status !== 'active') {
            throw new HttpException(403, '账号已被禁用，请联系平台');
        }

        $this->redis->del($failKey);
        $user->last_login_at = Carbon::now();
        $user->save();

        return ['token' => $this->tokenGuard->issue($user->id, $user->password), 'username' => $user->username];
    }

    /**
     * @return array<string, mixed>
     */
    public function me(MerchantUser $user, Merchant $merchant): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'real_name' => $user->real_name,
            'merchant_id' => $merchant->id,
            'merchant_name' => $merchant->name,
            'merchant_status' => $merchant->status,
        ];
    }

    /**
     * 修改自己的密码，返回新 token（旧 token 全部失效）。
     *
     * @return array{token: string}
     */
    public function changePassword(MerchantUser $user, string $oldPassword, string $newPassword): array
    {
        if (! password_verify($oldPassword, $user->password)) {
            throw new HttpException(422, '原密码不正确');
        }
        if ($newPassword === '') {
            throw new HttpException(422, '新密码不能为空');
        }
        if ($oldPassword === $newPassword) {
            throw new HttpException(422, '新密码不能和原密码相同');
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $this->merchantUserDao->update($user->id, ['password' => $hash]);

        return ['token' => $this->tokenGuard->issue($user->id, $hash)];
    }
}
