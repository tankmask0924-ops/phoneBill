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

use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;
use Throwable;

/**
 * 短信验证码：6 位数字，5 分钟有效，按「场景 + 手机号」存 Redis（只存哈希）。
 *
 * - 同一手机号 60 秒内只能发一次，一天最多发 10 次；
 * - 输错 5 次作废，要重新获取；验证通过立即作废，不能重复用。
 */
class SmsCodeService extends AbstractService
{
    public const SCENE_LOGIN = 'login';

    public const SCENE_BIND = 'bind';

    public const SCENE_RESET_SECRET = 'reset_secret';

    public const COOLDOWN_SECONDS = 60;

    private const TTL_SECONDS = 300;

    private const DAILY_LIMIT = 10;

    private const MAX_ATTEMPTS = 5;

    #[Inject]
    protected Redis $redis;

    #[Inject]
    protected SmsService $smsService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

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
        $dailyKey = "sms_daily:{$mobile}:" . date('Ymd');
        $count = (int) $this->redis->incr($dailyKey);
        $this->redis->expire($dailyKey, 86400);
        if ($count > self::DAILY_LIMIT) {
            throw new HttpException(429, '这个手机号今天获取验证码的次数太多，请明天再试');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        try {
            $this->smsService->sendCode($mobile, $code);
        } catch (Throwable $e) {
            $this->redis->del($cooldownKey);
            $this->redis->decr($dailyKey);
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

    private function codeKey(string $scene, string $mobile): string
    {
        return "sms_code:{$scene}:{$mobile}";
    }

    private function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
