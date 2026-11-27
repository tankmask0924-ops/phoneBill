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

namespace HyperfTest\Cases\Sms;

use App\Model\SystemSetting;
use App\Network\HttpClient;
use App\Service\Setting\SettingService;
use App\Service\Sms\SmsCodeService;
use App\Service\Sms\SmsService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\DbConnection\Pool\PoolFactory;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpMessage\Server\Request as ServerRequest;
use Hyperf\Redis\Redis;
use HyperfTest\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

use function Hyperf\Support\make;

/**
 * 发送验证码的频率限制：手机号 60 秒 / 1 小时 5 次 / 一天 10 次，IP 1 小时 10 次 / 一天 30 次；
 * 被拦下的不计数，发送失败撤销计数，回环地址不按 IP 限。管理后台、商户后台所有场景都走这里。
 *
 * @internal
 * @coversNothing
 */
class SmsCodeLimitTest extends TestCase
{
    private const SMS_OK = '{"Code":"OK","Message":"OK","BizId":"1","RequestId":"r"}';

    private const SMS_FAILED = '{"Code":"isv.BUSINESS_LIMIT_CONTROL","Message":"触发流控","RequestId":"r"}';

    private const CONFIG = [
        'enabled' => true,
        'access_key_id' => 'LTAI_TEST',
        'access_key_secret' => 'SECRET_TEST',
        'sign_name' => '测试签名',
        'code_template' => 'SMS_100001',
        'alert_template' => 'SMS_100002',
    ];

    private FakeHttpClient $http;

    private ?string $originalSms = null;

    private bool $hadSms = false;

    /** @var list<string> */
    private array $mobiles = [];

    /** @var list<string> */
    private array $ips = [];

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->http = new FakeHttpClient();
        $this->http->responses = [['status' => 200, 'body' => self::SMS_OK]];
        ApplicationContext::getContainer()->set(HttpClient::class, $this->http);
        $row = SystemSetting::where('key', SmsService::SETTING_KEY)->first();
        $this->hadSms = $row !== null;
        $this->originalSms = $row?->value;
        make(SettingService::class)->set(SmsService::SETTING_KEY, self::CONFIG);
    }

    protected function tearDown(): void
    {
        if ($this->hadSms) {
            SystemSetting::where('key', SmsService::SETTING_KEY)->update(['value' => $this->originalSms]);
        } else {
            SystemSetting::where('key', SmsService::SETTING_KEY)->delete();
        }
        $redis = make(Redis::class);
        $hour = date('YmdH');
        $day = date('Ymd');
        foreach ($this->mobiles as $mobile) {
            $redis->del("sms_cooldown:{$mobile}", "sms_hourly:{$mobile}:{$hour}", "sms_daily:{$mobile}:{$day}", "sms_code:login:{$mobile}");
        }
        foreach ($this->ips as $ip) {
            $redis->del("sms_ip_hourly:{$ip}:{$hour}", "sms_ip_daily:{$ip}:{$day}");
        }
        Context::destroy(ServerRequestInterface::class);
        ApplicationContext::getContainer()->get(PoolFactory::class)->flushAll();
    }

    public function testIpIsLimitedAcrossMobiles()
    {
        $ip = $this->fromIp();
        for ($i = 0; $i < 10; ++$i) {
            $this->service()->send(SmsCodeService::SCENE_LOGIN, $this->mobile());
        }
        $this->assertSame(429, $this->sendStatus($blocked = $this->mobile()), '同一 IP 1 小时最多 10 次');
        $this->assertCount(10, $this->http->requests);
        $this->assertSame('10', make(Redis::class)->get("sms_ip_hourly:{$ip}:" . date('YmdH')));
        $this->assertSame(0, (int) make(Redis::class)->get("sms_hourly:{$blocked}:" . date('YmdH')), '被 IP 拦下的不占这个手机号的次数');

        // 换个 IP 不受影响；本机回环地址（代理没配 TRUSTED_PROXIES 时）不按 IP 限
        $this->fromIp();
        $this->assertSame(200, $this->sendStatus($this->mobile()));
        $this->fromIp('127.0.0.1');
        $this->assertSame(200, $this->sendStatus($this->mobile()));
    }

    public function testMobileIsLimitedPerHour()
    {
        $this->fromIp();
        $mobile = $this->mobile();
        $redis = make(Redis::class);
        for ($i = 0; $i < 5; ++$i) {
            $this->service()->send(SmsCodeService::SCENE_LOGIN, $mobile);
            $redis->del("sms_cooldown:{$mobile}");
        }
        $this->assertSame(429, $this->sendStatus($mobile), '同一手机号 1 小时最多 5 次');
        $this->assertSame('5', $redis->get("sms_hourly:{$mobile}:" . date('YmdH')), '被拦下的这次不计数');
        $this->assertSame('5', $redis->get("sms_daily:{$mobile}:" . date('Ymd')));
    }

    public function testFailedSendDoesNotCount()
    {
        $ip = $this->fromIp();
        $this->http->responses = [['status' => 200, 'body' => self::SMS_FAILED]];
        $this->assertSame(422, $this->sendStatus($mobile = $this->mobile()));

        $redis = make(Redis::class);
        $this->assertSame('0', $redis->get("sms_hourly:{$mobile}:" . date('YmdH')));
        $this->assertSame('0', $redis->get("sms_ip_hourly:{$ip}:" . date('YmdH')));
        $this->assertSame('0', $redis->get("sms_ip_daily:{$ip}:" . date('Ymd')));
        $this->assertFalse($redis->get("sms_cooldown:{$mobile}"), '发送失败可以马上重试');
    }

    private function service(): SmsCodeService
    {
        return make(SmsCodeService::class);
    }

    private function sendStatus(string $mobile): int
    {
        try {
            $this->service()->send(SmsCodeService::SCENE_LOGIN, $mobile);
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }

        return 200;
    }

    /**
     * 模拟来自某个 IP 的请求（不传就随机一个 TEST-NET 地址）。
     */
    private function fromIp(?string $ip = null): string
    {
        $ip ??= '203.0.113.' . random_int(1, 254);
        if ($ip !== '127.0.0.1') {
            $this->ips[] = $ip;
            make(Redis::class)->del("sms_ip_hourly:{$ip}:" . date('YmdH'), "sms_ip_daily:{$ip}:" . date('Ymd'));
        }
        Context::set(ServerRequestInterface::class, (new ServerRequest('POST', '/'))->withServerParams(['remote_addr' => $ip]));

        return $ip;
    }

    private function mobile(): string
    {
        return $this->mobiles[] = '139' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
}
