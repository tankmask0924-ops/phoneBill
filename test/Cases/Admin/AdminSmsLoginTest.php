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

namespace HyperfTest\Cases\Admin;

use App\Model\AdminUser;
use App\Model\SecurityLog;
use App\Model\SystemSetting;
use App\Network\HttpClient;
use App\Service\Admin\AdminBootstrapService;
use App\Service\Setting\SettingService;
use App\Service\Sms\SmsService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\DbConnection\Pool\PoolFactory;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Redis\Redis;
use Hyperf\Testing\Client;
use HyperfTest\HttpTestCase;
use HyperfTest\Support\FakeHttpClient;
use InvalidArgumentException;

use function Hyperf\Support\make;

/**
 * 管理后台登录短信验证：平台启用了短信时，密码对了再输发到管理员手机号的验证码；没有手机号登不上，
 * admin:set-mobile 兜底；平台没启用短信时只校验密码。
 *
 * @internal
 * @coversNothing
 */
class AdminSmsLoginTest extends HttpTestCase
{
    use CreatesAdmins;

    private const PASSWORD = 'correct-password';

    private const SMS_OK = '{"Code":"OK","Message":"OK","BizId":"1","RequestId":"r"}';

    private const CONFIG = [
        'enabled' => true,
        'access_key_id' => 'LTAI_TEST',
        'access_key_secret' => 'SECRET_TEST',
        'sign_name' => '测试签名',
        'code_template' => 'SMS_100001',
        'alert_template' => 'SMS_100002',
    ];

    private FakeHttpClient $http;

    /** 测试前的短信配置（测试库和开发库共用，测完还原） */
    private ?string $originalSms = null;

    private bool $hadSms = false;

    /** @var list<string> */
    private array $mobiles = [];

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->http = new FakeHttpClient();
        $this->http->responses = [['status' => 200, 'body' => self::SMS_OK]];
        ApplicationContext::getContainer()->set(HttpClient::class, $this->http);
        $this->client = make(Client::class);
        $row = SystemSetting::where('key', SmsService::SETTING_KEY)->first();
        $this->hadSms = $row !== null;
        $this->originalSms = $row?->value;
    }

    protected function tearDown(): void
    {
        if ($this->hadSms) {
            SystemSetting::where('key', SmsService::SETTING_KEY)->update(['value' => $this->originalSms]);
        } else {
            SystemSetting::where('key', SmsService::SETTING_KEY)->delete();
        }
        $redis = make(Redis::class);
        foreach ($this->mobiles as $mobile) {
            $redis->del("sms_cooldown:{$mobile}", "sms_daily:{$mobile}:" . date('Ymd'), "sms_code:admin_login:{$mobile}");
        }
        $this->cleanUpAdmins();
        parent::tearDown();
        ApplicationContext::getContainer()->get(PoolFactory::class)->flushAll();
    }

    public function testLoginNeedsSmsCodeOnceSmsIsAvailable()
    {
        $admin = $this->adminWithMobile($mobile = $this->mobile());

        // 平台没开短信：只校验密码
        $this->assertArrayHasKey('token', $this->login($admin->username));

        make(SettingService::class)->set(SmsService::SETTING_KEY, self::CONFIG);
        $this->assertSame(401, $this->rawLogin($admin->username, 'wrong')->getStatusCode(), '密码错了不发验证码');
        $this->assertSame([], $this->http->requests);

        $step1 = $this->login($admin->username);
        $this->assertArrayNotHasKey('token', $step1);
        $this->assertTrue($step1['need_sms']);
        $this->assertTrue($step1['code_sent']);
        $this->assertSame(substr($mobile, 0, 3) . '****' . substr($mobile, -4), $step1['mobile']);
        $this->assertSame($mobile, $this->http->requests[0]['payload']['PhoneNumbers']);
        $this->assertSame(0, AdminUser::find($admin->id)->login_failures, '密码对了就清零');
        $code = $this->lastCode();

        $this->assertSame(429, $this->jsonRequest('POST', '/admin/auth/login/sms-code', null, ['ticket' => $step1['ticket']])->getStatusCode(), '60 秒内不能重发');
        $this->assertSame(422, $this->jsonRequest('POST', '/admin/auth/login/verify', null, ['ticket' => $step1['ticket'], 'code' => $this->wrong($code)])->getStatusCode());
        $this->assertSame(401, $this->jsonRequest('POST', '/admin/auth/login/verify', null, ['ticket' => 'bad', 'code' => $code])->getStatusCode());

        $verified = $this->body($this->jsonRequest('POST', '/admin/auth/login/verify', null, ['ticket' => $step1['ticket'], 'code' => $code]));
        $this->assertSame($admin->username, $verified['username']);
        $this->assertSame($mobile, $this->body($this->jsonRequest('GET', '/admin/auth/me', $verified['token']))['mobile']);
        $this->assertSame(401, $this->jsonRequest('POST', '/admin/auth/login/verify', null, ['ticket' => $step1['ticket'], 'code' => $code])->getStatusCode(), 'ticket 只能用一次');

        $events = SecurityLog::where('portal', SecurityLog::PORTAL_ADMIN)->where('user_id', $admin->id)->orderBy('id')->pluck('event')->all();
        $this->assertSame([SecurityLog::EVENT_LOGIN_SUCCESS, SecurityLog::EVENT_LOGIN_FAILED, SecurityLog::EVENT_LOGIN_SMS_SENT, SecurityLog::EVENT_LOGIN_FAILED, SecurityLog::EVENT_LOGIN_SUCCESS], $events);

        // 换了手机号：旧 ticket 作废
        make(Redis::class)->del("sms_cooldown:{$mobile}");
        $ticket = $this->login($admin->username)['ticket'];
        AdminUser::where('id', $admin->id)->update(['mobile' => $this->mobile()]);
        $this->assertSame(401, $this->jsonRequest('POST', '/admin/auth/login/verify', null, ['ticket' => $ticket, 'code' => $this->lastCode()])->getStatusCode());
    }

    public function testAdminWithoutMobileCannotLoginWhileSmsIsOn()
    {
        $admin = $this->createAdminWithPermissions(['operation_log.view']);
        make(SettingService::class)->set(SmsService::SETTING_KEY, self::CONFIG);

        $this->assertSame(403, $this->rawLogin($admin->username, self::PASSWORD)->getStatusCode());
        $failed = SecurityLog::where('portal', SecurityLog::PORTAL_ADMIN)->where('user_id', $admin->id)->orderByDesc('id')->first();
        $this->assertSame([SecurityLog::EVENT_LOGIN_FAILED, SecurityLog::REASON_NO_MOBILE], [$failed->event, $failed->detail['reason']]);

        // 命令行兜底设置手机号
        $service = make(AdminBootstrapService::class);
        try {
            $service->setAdminMobile($admin->username, '12345');
            $this->fail('手机号不对应该报错');
        } catch (InvalidArgumentException) {
        }
        $mobile = $this->mobile();
        $this->assertSame($mobile, $service->setAdminMobile($admin->username, $mobile)->mobile);
        $this->assertTrue($this->login($admin->username)['need_sms']);
    }

    public function testMerchantLoginCodeIsNotAcceptedForAdmin()
    {
        make(SettingService::class)->set(SmsService::SETTING_KEY, self::CONFIG);
        $admin = $this->adminWithMobile($mobile = $this->mobile());
        $ticket = $this->login($admin->username)['ticket'];
        $code = $this->lastCode();

        // 同一个手机号的商户后台登录验证码存在另一个场景里，不能拿来登录管理后台
        make(Redis::class)->del("sms_code:admin_login:{$mobile}");
        make(Redis::class)->setex("sms_code:login:{$mobile}", 300, (string) json_encode(['hash' => hash('sha256', $code), 'attempts' => 0]));
        $this->assertSame(422, $this->jsonRequest('POST', '/admin/auth/login/verify', null, ['ticket' => $ticket, 'code' => $code])->getStatusCode());
        make(Redis::class)->del("sms_code:login:{$mobile}");
    }

    private function adminWithMobile(string $mobile): AdminUser
    {
        $admin = $this->createAdminWithPermissions(['operation_log.view']);
        AdminUser::where('id', $admin->id)->update(['mobile' => $mobile]);

        return AdminUser::find($admin->id);
    }

    private function mobile(): string
    {
        return $this->mobiles[] = '139' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    private function lastCode(): string
    {
        $last = end($this->http->requests);

        return json_decode($last['payload']['TemplateParam'], true)['code'];
    }

    private function wrong(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    /**
     * @return array<string, mixed>
     */
    private function login(string $username): array
    {
        return $this->body($this->rawLogin($username, self::PASSWORD));
    }

    private function rawLogin(string $username, string $password)
    {
        return $this->client->request('POST', '/admin/auth/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['username' => $username, 'password' => $password],
        ]);
    }
}
