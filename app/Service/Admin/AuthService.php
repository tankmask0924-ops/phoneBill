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

namespace App\Service\Admin;

use App\Auth\AdminJwtGuard;
use App\Dao\AdminRoleDao;
use App\Dao\AdminRolePermissionDao;
use App\Dao\AdminUserDao;
use App\Model\AdminUser;
use App\Model\SecurityLog;
use App\Service\AbstractService;
use App\Service\Auth\LoginLockoutService;
use App\Service\Security\SecurityLogService;
use App\Service\Sms\SmsCodeService;
use App\Service\Sms\SmsService;
use App\Support\Mobile;
use Carbon\Carbon;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Redis\Redis;

/**
 * 管理后台账户登录、当前管理员信息、修改自己的密码。
 *
 * - 登录密码连续输错 5 次自动封禁账号（LoginLockoutService），在「管理员账号」里重新启用或用 admin:unlock 解封；
 * - 平台启用了短信时登录分两步：账号密码对了先给管理员的手机号发验证码、返回一个 10 分钟有效的 ticket，
 *   再用 ticket + 验证码换 token（verifyLogin）。还没设置手机号的管理员登不上，在「管理员账号」里补上手机号，
 *   或用 admin:set-mobile 设置。平台没启用短信时只校验密码，避免短信出问题时所有人都登不上；
 * - 登录成功 / 失败、改自己的密码都记安全日志（SecurityLogService，带 IP 和 User-Agent）。
 */
class AuthService extends AbstractService
{
    private const GENERIC_LOGIN_FAIL_MESSAGE = '账号或密码错误';

    private const TICKET_TTL_SECONDS = 600;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    #[Inject]
    protected AdminJwtGuard $tokenGuard;

    #[Inject]
    protected AdminRoleDao $adminRoleDao;

    #[Inject]
    protected AdminRolePermissionDao $adminRolePermissionDao;

    #[Inject]
    protected LoginLockoutService $loginLockoutService;

    #[Inject]
    protected SecurityLogService $securityLogService;

    #[Inject]
    protected SmsService $smsService;

    #[Inject]
    protected SmsCodeService $smsCodeService;

    #[Inject]
    protected Redis $redis;

    /**
     * 不用短信验证时直接返回 token；要验证时返回 ticket（验证码已发出，code_sent 为 false 表示 60 秒内刚发过，没再发）。
     *
     * @return array{need_sms: true, ticket: string, mobile: string, code_sent: bool}|array{token: string, username: string}
     */
    public function login(string $username, string $password): array
    {
        $username = trim($username);

        $adminUser = $this->adminUserDao->findByUsername($username);

        if (! $adminUser) {
            $this->securityLogService->loginFailed(SecurityLog::PORTAL_ADMIN, null, $username, null, SecurityLog::REASON_UNKNOWN_USER);
            // 账号不存在 / 密码错误共用同一条消息，防止枚举出哪些用户名存在
            throw new HttpException(401, self::GENERIC_LOGIN_FAIL_MESSAGE);
        }
        if (! password_verify($password, $adminUser->password)) {
            $this->loginLockoutService->recordFailure($this->adminUserDao, $adminUser->id, $adminUser->username, 'admin', '超级管理员');
        }

        if ($adminUser->status === 'disabled') {
            $this->securityLogService->loginFailed(SecurityLog::PORTAL_ADMIN, $adminUser->id, $adminUser->username, null, SecurityLog::REASON_DISABLED);
            // 密码本身是对的，只是账号被禁用，跟「账号或密码错误」是不同性质的失败，用 403 而不是 401
            throw new HttpException(403, '账号已被禁用');
        }

        // 密码对了就清零（要短信验证的，验证码错不算密码错）
        $this->adminUserDao->resetLoginFailures($adminUser->id);

        if (! $this->smsService->available()) {
            return $this->finishLogin($adminUser);
        }
        if (($adminUser->mobile ?? '') === '') {
            $this->securityLogService->loginFailed(SecurityLog::PORTAL_ADMIN, $adminUser->id, $adminUser->username, null, SecurityLog::REASON_NO_MOBILE);
            throw new HttpException(403, '账号还没有设置手机号，收不到登录验证码，请联系超级管理员设置');
        }
        $ticket = bin2hex(random_bytes(24));
        $this->redis->setex($this->ticketKey($ticket), self::TICKET_TTL_SECONDS, (string) json_encode(['user_id' => $adminUser->id, 'mobile' => $adminUser->mobile]));
        $this->securityLogService->record(SecurityLog::PORTAL_ADMIN, SecurityLog::EVENT_LOGIN_SMS_SENT, $adminUser->id, $adminUser->username);

        return ['need_sms' => true, 'ticket' => $ticket, 'mobile' => Mobile::mask((string) $adminUser->mobile), 'code_sent' => $this->sendLoginCode((string) $adminUser->mobile)];
    }

    /**
     * 重新发送登录验证码（60 秒一次）。
     *
     * @return array{mobile: string}
     */
    public function resendLoginCode(string $ticket): array
    {
        [, $mobile] = $this->ticket($ticket);
        $this->smsCodeService->send(SmsCodeService::SCENE_ADMIN_LOGIN, $mobile);

        return ['mobile' => Mobile::mask($mobile)];
    }

    /**
     * 第二步：ticket + 验证码换 token。
     *
     * @return array{token: string, username: string}
     */
    public function verifyLogin(string $ticket, string $code): array
    {
        [$adminUser, $mobile] = $this->ticket($ticket);
        try {
            $this->smsCodeService->verify(SmsCodeService::SCENE_ADMIN_LOGIN, $mobile, $code);
        } catch (HttpException $e) {
            $this->securityLogService->loginFailed(SecurityLog::PORTAL_ADMIN, $adminUser->id, $adminUser->username, null, SecurityLog::REASON_SMS_CODE);
            throw $e;
        }
        $this->redis->del($this->ticketKey($ticket));

        return $this->finishLogin($adminUser);
    }

    /**
     * 当前登录管理员的资料和权限编码，前端据此隐藏没有权限的菜单和按钮。
     *
     * @return array<string, mixed>
     */
    public function me(AdminUser $admin): array
    {
        $role = $this->adminRoleDao->find($admin->role_id);

        return [
            'id' => $admin->id,
            'username' => $admin->username,
            'real_name' => $admin->real_name,
            'mobile' => $admin->mobile,
            'role_id' => $admin->role_id,
            'role_name' => $role?->name,
            'is_super_admin' => $role?->name === AdminBootstrapService::SUPER_ADMIN_ROLE_NAME,
            'status' => $admin->status,
            'permissions' => $this->adminRolePermissionDao->codesForRole($admin->role_id),
        ];
    }

    /**
     * 修改自己的密码，返回新 token（旧 token 全部失效）。
     *
     * @return array{token: string}
     */
    public function changePassword(AdminUser $admin, string $oldPassword, string $newPassword): array
    {
        if (! password_verify($oldPassword, $admin->password)) {
            throw new HttpException(422, '原密码不正确');
        }
        if ($newPassword === '') {
            throw new HttpException(422, '新密码不能为空');
        }
        if ($oldPassword === $newPassword) {
            throw new HttpException(422, '新密码不能和原密码相同');
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $this->adminUserDao->update($admin->id, ['password' => $hash]);
        $this->securityLogService->record(SecurityLog::PORTAL_ADMIN, SecurityLog::EVENT_CHANGE_PASSWORD, $admin->id, $admin->username);

        return ['token' => $this->tokenGuard->issue($admin->id, $hash)];
    }

    /**
     * 登录时自动发一次验证码；60 秒内刚发过就不再发（之前那条还有效），发送失败照常报错。
     */
    private function sendLoginCode(string $mobile): bool
    {
        try {
            $this->smsCodeService->send(SmsCodeService::SCENE_ADMIN_LOGIN, $mobile);
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 429) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /**
     * @return array{0: AdminUser, 1: string} 管理员、发验证码的手机号
     */
    private function ticket(string $ticket): array
    {
        $data = $ticket === '' ? null : json_decode((string) $this->redis->get($this->ticketKey($ticket)), true);
        $adminUser = is_array($data) ? $this->adminUserDao->find((int) $data['user_id']) : null;
        // 中途账号被禁用、手机号被改了，都要重新登录
        if ($adminUser === null || $adminUser->status !== 'active' || $adminUser->mobile !== $data['mobile']) {
            throw new HttpException(401, '登录已过期，请重新输入账号密码');
        }

        return [$adminUser, (string) $adminUser->mobile];
    }

    /**
     * @return array{token: string, username: string}
     */
    private function finishLogin(AdminUser $adminUser): array
    {
        $adminUser->last_login_at = Carbon::now();
        $adminUser->save();
        $this->securityLogService->record(SecurityLog::PORTAL_ADMIN, SecurityLog::EVENT_LOGIN_SUCCESS, $adminUser->id, $adminUser->username);

        return ['token' => $this->tokenGuard->issue($adminUser->id, $adminUser->password), 'username' => $adminUser->username];
    }

    private function ticketKey(string $ticket): string
    {
        return 'admin_login_ticket:' . $ticket;
    }
}
