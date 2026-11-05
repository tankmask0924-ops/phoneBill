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
use App\Service\Sms\SmsCodeService;
use App\Service\Sms\SmsService;
use App\Support\Mobile;
use Carbon\Carbon;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;

/**
 * 商户后台账户：登录、当前账号信息、修改自己的密码、绑定 / 修改手机。
 *
 * - 商户后台放在外网，登录加了失败次数限制：同一账号 15 分钟内失败 10 次就暂时锁住；
 * - 绑定了手机并且平台启用了短信时，登录分两步：账号密码对了先发验证码、返回一个 10 分钟有效的 ticket，
 *   再用 ticket + 验证码换 token（verifyLogin）。平台没启用短信时只校验密码，避免短信出问题时所有人都登不上；
 * - 绑定 / 修改手机要输登录密码，并且收到新手机号的验证码。
 */
class MerchantAuthService extends AbstractService
{
    private const GENERIC_LOGIN_FAIL_MESSAGE = '账号或密码错误';

    private const MAX_FAILURES = 10;

    private const FAILURE_WINDOW_SECONDS = 900;

    private const TICKET_TTL_SECONDS = 600;

    #[Inject]
    protected MerchantUserDao $merchantUserDao;

    #[Inject]
    protected MerchantJwtGuard $tokenGuard;

    #[Inject]
    protected Redis $redis;

    #[Inject]
    protected SmsService $smsService;

    #[Inject]
    protected SmsCodeService $smsCodeService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 不用短信验证时直接返回 token；要验证时返回 ticket（验证码已发出，code_sent 为 false 表示 60 秒内刚发过，没再发）。
     *
     * @return array{need_sms: true, ticket: string, mobile: string, code_sent: bool}|array{token: string, username: string}
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

        if (! $this->needsSms($user)) {
            return $this->finishLogin($user);
        }
        $ticket = bin2hex(random_bytes(24));
        $this->redis->setex($this->ticketKey($ticket), self::TICKET_TTL_SECONDS, (string) json_encode(['user_id' => $user->id, 'mobile' => $user->mobile]));

        return ['need_sms' => true, 'ticket' => $ticket, 'mobile' => Mobile::mask((string) $user->mobile), 'code_sent' => $this->sendLoginCode((string) $user->mobile)];
    }

    /**
     * 重新发送登录验证码（60 秒一次）。
     *
     * @return array{mobile: string}
     */
    public function resendLoginCode(string $ticket): array
    {
        [, $mobile] = $this->ticket($ticket);
        $this->smsCodeService->send(SmsCodeService::SCENE_LOGIN, $mobile);

        return ['mobile' => Mobile::mask($mobile)];
    }

    /**
     * 第二步：ticket + 验证码换 token。
     *
     * @return array{token: string, username: string}
     */
    public function verifyLogin(string $ticket, string $code): array
    {
        [$user, $mobile] = $this->ticket($ticket);
        $this->smsCodeService->verify(SmsCodeService::SCENE_LOGIN, $mobile, $code);
        $this->redis->del($this->ticketKey($ticket));

        return $this->finishLogin($user);
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
            'mobile' => $user->mobile,
            'sms_available' => $this->smsService->available(),
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

    /**
     * 给要绑定的新手机号发验证码。
     */
    public function sendBindCode(MerchantUser $user, mixed $mobile): void
    {
        $mobile = $this->newMobile($user, $mobile);
        $this->smsCodeService->send(SmsCodeService::SCENE_BIND, $mobile);
    }

    /**
     * 绑定或修改手机：登录密码 + 新手机号收到的验证码。
     *
     * @return array{mobile: string}
     */
    public function bindMobile(MerchantUser $user, string $password, mixed $mobile, string $code, ?string $ip): array
    {
        if ($password === '' || ! password_verify($password, $user->password)) {
            throw new HttpException(422, '登录密码不正确');
        }
        $mobile = $this->newMobile($user, $mobile);
        $this->smsCodeService->verify(SmsCodeService::SCENE_BIND, $mobile, $code);
        $before = $user->mobile;
        $this->merchantUserDao->update($user->id, ['mobile' => $mobile]);
        $this->loggerFactory->get('merchant')->info($before === null ? '商户账号绑定手机' : '商户账号修改绑定手机', [
            'merchant_id' => $user->merchant_id,
            'user' => $user->username,
            'ip' => $ip,
            'before' => $before === null ? null : Mobile::mask($before),
            'after' => Mobile::mask($mobile),
        ]);

        return ['mobile' => $mobile];
    }

    private function needsSms(MerchantUser $user): bool
    {
        return ($user->mobile ?? '') !== '' && $this->smsService->available();
    }

    /**
     * 登录时自动发一次验证码；60 秒内刚发过就不再发（之前那条还有效），发送失败照常报错。
     */
    private function sendLoginCode(string $mobile): bool
    {
        try {
            $this->smsCodeService->send(SmsCodeService::SCENE_LOGIN, $mobile);
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 429) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /**
     * @return array{0: MerchantUser, 1: string} 账号、发验证码的手机号
     */
    private function ticket(string $ticket): array
    {
        $data = $ticket === '' ? null : json_decode((string) $this->redis->get($this->ticketKey($ticket)), true);
        $user = is_array($data) ? $this->merchantUserDao->find((int) $data['user_id']) : null;
        // 中途账号被禁用、手机号被改了，都要重新登录
        if ($user === null || $user->status !== 'active' || $user->mobile !== $data['mobile']) {
            throw new HttpException(401, '登录已过期，请重新输入账号密码');
        }

        return [$user, (string) $user->mobile];
    }

    /**
     * @return array{token: string, username: string}
     */
    private function finishLogin(MerchantUser $user): array
    {
        $user->last_login_at = Carbon::now();
        $user->save();

        return ['token' => $this->tokenGuard->issue($user->id, $user->password), 'username' => $user->username];
    }

    private function newMobile(MerchantUser $user, mixed $mobile): string
    {
        if (! $this->smsService->available()) {
            throw new HttpException(422, '平台还没有开通短信服务，暂时不能绑定手机');
        }
        $mobile = Mobile::normalize($mobile) ?? throw new HttpException(422, '请填写正确的手机号');
        if ($mobile === $user->mobile) {
            throw new HttpException(422, '和当前绑定的手机号相同');
        }

        return $mobile;
    }

    private function ticketKey(string $ticket): string
    {
        return 'merchant_login_ticket:' . $ticket;
    }
}
