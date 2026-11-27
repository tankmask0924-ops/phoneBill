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

namespace App\Service\Sms;

use App\Network\ClientIpResolver;
use App\Network\IpAddress;
use App\Service\AbstractService;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * 短信验证码：6 位数字，5 分钟有效，按「场景 + 手机号」存 Redis（只存哈希）。
 *
 * - 同一手机号 60 秒内只能发一次，1 小时最多 5 次，一天最多 10 次；
 * - 同一 IP（所有场景、所有手机号合计）1 小时最多 10 次，一天最多 30 次；IP 取法同操作日志
 *   （ClientIpResolver，反向代理后面要配 TRUSTED_PROXIES），取不到或是本机回环地址（代理没配好时
 *   所有请求都是 127.0.0.1）不按 IP 限，免得一个人刷满全站都发不了；
 * - 被限制拦下的这次不计数，发送失败的撤销计数；
 * - 输错 5 次作废，要重新获取；验证通过立即作废，不能重复用。
 */
class SmsCodeService extends AbstractService
{
    public const SCENE_LOGIN = 'login';

    public const SCENE_BIND = 'bind';

    public const SCENE_RESET_SECRET = 'reset_secret';

    /** 管理后台登录（和商户后台登录分开，同一个手机号两边都有账号时互不影响） */
    public const SCENE_ADMIN_LOGIN = 'admin_login';

    public const COOLDOWN_SECONDS = 60;

    private const TTL_SECONDS = 300;

    private const HOURLY_LIMIT = 5;

    private const DAILY_LIMIT = 10;

    private const IP_HOURLY_LIMIT = 10;

    private const IP_DAILY_LIMIT = 30;

    private const MAX_ATTEMPTS = 5;

    #[Inject]
    protected Redis $redis;

    #[Inject]
    protected SmsService $smsService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Inject]
    protected RequestInterface $request;

    /**
     * 发送验证码。发送失败时撤销冷却和次数，可以马上重试。
     *
     * @throws HttpException 发得太频繁（429）、短信没配置或发送失败（422）
     */
    public function send(string $scene, string $mobile): void
    {
        $cooldownKey = "sms_cooldown:{$mobile}";
        if (! $this->redis->set($cooldownKey, '1', ['nx', 'ex' => self::COOLDOWN_SECONDS])) {
            $ttl = max(1, (int) $this->redis->ttl($cooldownKey));
            throw new HttpException(429, "验证码发送太频繁，请 {$ttl} 秒后再试");
        }
        $counters = $this->countUp($mobile, $this->clientIp());

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        try {
            $this->smsService->sendCode($mobile, $code);
        } catch (Throwable $e) {
            $this->redis->del($cooldownKey);
            $this->countDown($counters);
            $this->loggerFactory->get('sms')->error('发送验证码失败', ['scene' => $scene, 'mobile' => $mobile, 'error' => $e->getMessage()]);
            throw new HttpException(422, '验证码发送失败，请稍后再试或联系平台');
        }
        $this->redis->setex($this->codeKey($scene, $mobile), self::TTL_SECONDS, (string) json_encode(['hash' => $this->hash($code), 'attempts' => 0]));
    }

    /**
     * 校验验证码，通过后作废。
     *
     * @throws HttpException 过期、没发过或不正确（422）
     */
    public function verify(string $scene, string $mobile, string $code): void
    {
        $key = $this->codeKey($scene, $mobile);
        $stored = json_decode((string) $this->redis->get($key), true);
        if (! is_array($stored)) {
            throw new HttpException(422, '验证码已失效，请重新获取');
        }
        if (! hash_equals((string) $stored['hash'], $this->hash(trim($code)))) {
            $attempts = (int) $stored['attempts'] + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->redis->del($key);
                throw new HttpException(422, '验证码错误次数太多，请重新获取');
            }
            $ttl = (int) $this->redis->ttl($key);
            if ($ttl > 0) {
                $this->redis->setex($key, $ttl, (string) json_encode(['hash' => $stored['hash'], 'attempts' => $attempts]));
            }
            throw new HttpException(422, '验证码不正确');
        }
        $this->redis->del($key);
    }

    /**
     * 手机号、IP 各时段的发送次数加 1；有一项超了就全部撤回（这次不算）并报 429。
     *
     * @return list<string> 加过的计数 key，发送失败时撤回
     */
    private function countUp(string $mobile, ?string $ip): array
    {
        $hour = date('YmdH');
        $day = date('Ymd');
        $limits = [
            ["sms_hourly:{$mobile}:{$hour}", 3600, self::HOURLY_LIMIT, '这个手机号获取验证码太频繁，请 1 小时后再试'],
            ["sms_daily:{$mobile}:{$day}", 86400, self::DAILY_LIMIT, '这个手机号今天获取验证码的次数太多，请明天再试'],
        ];
        if ($ip !== null) {
            $limits[] = ["sms_ip_hourly:{$ip}:{$hour}", 3600, self::IP_HOURLY_LIMIT, '当前网络获取验证码太频繁，请 1 小时后再试'];
            $limits[] = ["sms_ip_daily:{$ip}:{$day}", 86400, self::IP_DAILY_LIMIT, '当前网络今天获取验证码的次数太多，请明天再试'];
        }

        $counted = [];
        foreach ($limits as [$key, $ttl, $limit, $message]) {
            $count = (int) $this->redis->incr($key);
            $this->redis->expire($key, $ttl);
            $counted[] = $key;
            if ($count > $limit) {
                $this->countDown($counted);
                throw new HttpException(429, $message);
            }
        }

        return $counted;
    }

    /**
     * @param list<string> $keys
     */
    private function countDown(array $keys): void
    {
        foreach ($keys as $key) {
            $this->redis->decr($key);
        }
    }

    /**
     * 当前请求的客户端 IP；不在 HTTP 请求里、取不到或是回环地址时返回 null（不按 IP 限）。
     */
    private function clientIp(): ?string
    {
        if (! Context::has(ServerRequestInterface::class)) {
            return null;
        }
        $ip = $this->clientIpResolver->resolve($this->request);
        if ($ip === null || IpAddress::inRange($ip, '127.0.0.0/8') || IpAddress::equals($ip, '::1')) {
            return null;
        }

        return $ip;
    }

    private function codeKey(string $scene, string $mobile): string
    {
        return "sms_code:{$scene}:{$mobile}";
    }

    private function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
