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

namespace HyperfTest\Cases\Merchant;

use App\Model\Merchant;
use App\Model\MerchantUser;
use App\Model\Order;
use App\Model\SystemSetting;
use App\Network\HttpClient;
use App\Service\Merchant\MerchantBalanceAlertService;
use App\Service\Merchant\MerchantConsumptionService;
use App\Service\Setting\SettingService;
use App\Service\Sms\SmsService;
use App\Sms\AliyunSmsClient;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\DbConnection\Pool\PoolFactory;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Redis\Redis;
use Hyperf\Testing\Client;
use HyperfTest\Cases\Admin\CreatesAdmins;
use HyperfTest\Cases\Admin\CreatesCatalog;
use HyperfTest\HttpTestCase;
use HyperfTest\Support\FakeHttpClient;

use function Hyperf\Support\make;

/**
 * 短信：平台配置阿里云短信、商户绑定手机、绑定后登录要验证码、余额提醒（跌破一次提醒一次，充值后重新开启，每天有上限）、商户列表的今日 / 累计消费。
 *
 * @internal
 * @coversNothing
 */
class MerchantSmsTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

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
            $redis->del("sms_cooldown:{$mobile}", "sms_daily:{$mobile}:" . date('Ymd'), "sms_code:login:{$mobile}", "sms_code:bind:{$mobile}");
        }
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
        // setUp 重建了容器，每次都会新建一个数据库连接池；关掉空闲连接，免得全量测试时超过 MySQL 的 max_connections
        ApplicationContext::getContainer()->get(PoolFactory::class)->flushAll();
    }

    public function testSignatureMatchesAliyunExample()
    {
        // 阿里云文档里的签名示例
        $client = new AliyunSmsClient($this->http, 'testId', 'testSecret');
        $query = $client->signedQuery([
            'Action' => 'SendSms', 'Version' => '2017-05-25', 'RegionId' => 'cn-hangzhou', 'PhoneNumbers' => '15300000001',
            'SignName' => '阿里云短信测试专用', 'TemplateCode' => 'SMS_71390007', 'TemplateParam' => '{"customer":"test"}', 'OutId' => '123', 'Format' => 'XML',
        ], '45e25e9b-0a6f-4070-8c85-2956eda1b466', '2017-07-12T02:42:19Z');
        $this->assertSame('zJDF+Lrzhj/ThnlvIToysFRq6t4=', $query['Signature']);
    }

    public function testSmsSetting()
    {
        $admin = $this->loginAs($this->createAdminWithPermissions(['setting.manage']));
        $this->assertSame(422, $this->jsonRequest('PUT', '/admin/settings/sms', $admin, ['code_template' => '123'] + self::CONFIG)->getStatusCode());
        $saved = $this->body($this->jsonRequest('PUT', '/admin/settings/sms', $admin, self::CONFIG));
        $this->assertTrue($saved['access_key_secret_set']);
        $this->assertArrayNotHasKey('access_key_secret', $saved);
        $this->assertSame('SMS_100002', $this->body($this->jsonRequest('GET', '/admin/settings/sms', $admin))['alert_template']);

        // Secret 留空不改
        $this->jsonRequest('PUT', '/admin/settings/sms', $admin, ['access_key_secret' => ''] + self::CONFIG);
        $this->assertSame('SECRET_TEST', make(SmsService::class)->config()['access_key_secret']);

        // 测试发送
        $mobile = $this->mobile();
        $this->assertSame(422, $this->jsonRequest('POST', '/admin/settings/sms/test', $admin, ['mobile' => '123'])->getStatusCode());
        $this->assertTrue($this->body($this->jsonRequest('POST', '/admin/settings/sms/test', $admin, ['mobile' => $mobile]))['success']);
        $sent = $this->http->requests[0];
        $this->assertSame(['GET', AliyunSmsClient::ENDPOINT], [$sent['method'], $sent['url']]);
        $this->assertSame([$mobile, 'SMS_100001', '测试签名', 'LTAI_TEST'], [$sent['payload']['PhoneNumbers'], $sent['payload']['TemplateCode'], $sent['payload']['SignName'], $sent['payload']['AccessKeyId']]);
        $this->assertArrayHasKey('Signature', $sent['payload']);

        $this->http->responses = [['status' => 200, 'body' => '{"Code":"isv.BUSINESS_LIMIT_CONTROL","Message":"触发流控"}']];
        $failed = $this->body($this->jsonRequest('POST', '/admin/settings/sms/test', $admin, ['mobile' => $mobile]));
        $this->assertFalse($failed['success']);
        $this->assertStringContainsString('isv.BUSINESS_LIMIT_CONTROL', $failed['message']);

        // 关掉之后算没配置
        $this->jsonRequest('PUT', '/admin/settings/sms', $admin, ['enabled' => false] + self::CONFIG);
        $this->assertFalse(make(SmsService::class)->available());
    }

    public function testBindMobileAndLoginWithSmsCode()
    {
        $user = $this->createMerchantUser($this->createMerchant());
        $token = $this->merchantLogin($user->username)['token'];
        $mobile = $this->mobile();

        // 平台没开短信：不能绑定
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/auth/mobile/code', $token, ['mobile' => $mobile])->getStatusCode());
        make(SettingService::class)->set(SmsService::SETTING_KEY, self::CONFIG);
        $this->assertTrue($this->body($this->jsonRequest('GET', '/merchant/auth/me', $token))['sms_available']);
        $this->assertSame(422, $this->jsonRequest('PUT', '/merchant/auth/sms-login', $token, ['enabled' => true, 'password' => self::PASSWORD])->getStatusCode(), '没绑定手机不能开启');

        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/auth/mobile/code', $token, ['mobile' => '12345'])->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('POST', '/merchant/auth/mobile/code', $token, ['mobile' => $mobile])->getStatusCode());
        $this->assertSame(429, $this->jsonRequest('POST', '/merchant/auth/mobile/code', $token, ['mobile' => $mobile])->getStatusCode(), '60 秒内不能重发');
        $code = $this->lastCode();

        $bind = ['mobile' => $mobile, 'code' => $code, 'password' => self::PASSWORD];
        $this->assertSame(422, $this->jsonRequest('PUT', '/merchant/auth/mobile', $token, ['password' => 'wrong'] + $bind)->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('PUT', '/merchant/auth/mobile', $token, ['code' => $this->wrong($code)] + $bind)->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('PUT', '/merchant/auth/mobile', $token, $bind)->getStatusCode());
        $this->assertSame($mobile, MerchantUser::find($user->id)->mobile);
        $this->assertSame(422, $this->jsonRequest('PUT', '/merchant/auth/mobile', $token, $bind)->getStatusCode(), '验证码用过就作废');

        // 只绑定、没开启登录短信验证：照常只校验密码
        $this->assertArrayHasKey('token', $this->merchantLogin($user->username));
        $this->assertFalse($this->body($this->jsonRequest('GET', '/merchant/auth/me', $token))['sms_login_enabled']);

        // 开启要输对登录密码
        $this->assertSame(422, $this->jsonRequest('PUT', '/merchant/auth/sms-login', $token, ['enabled' => true, 'password' => 'wrong'])->getStatusCode());
        $this->assertTrue($this->body($this->jsonRequest('PUT', '/merchant/auth/sms-login', $token, ['enabled' => true, 'password' => self::PASSWORD]))['sms_login_enabled']);
        $this->assertTrue($this->body($this->jsonRequest('GET', '/merchant/auth/me', $token))['sms_login_enabled']);

        // 开启后登录：先拿 ticket，验证码已自动发出（刚发过绑定码，在冷却里就不再发）
        make(Redis::class)->del("sms_cooldown:{$mobile}");
        $step1 = $this->merchantLogin($user->username);
        $this->assertArrayNotHasKey('token', $step1);
        $this->assertTrue($step1['need_sms']);
        $this->assertTrue($step1['code_sent']);
        $this->assertSame(substr($mobile, 0, 3) . '****' . substr($mobile, -4), $step1['mobile']);
        $code = $this->lastCode();
        $this->assertSame(429, $this->jsonRequest('POST', '/merchant/auth/login/sms-code', null, ['ticket' => $step1['ticket']])->getStatusCode());

        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/auth/login/verify', null, ['ticket' => $step1['ticket'], 'code' => $this->wrong($code)])->getStatusCode());
        $this->assertSame(401, $this->jsonRequest('POST', '/merchant/auth/login/verify', null, ['ticket' => 'bad', 'code' => $code])->getStatusCode());
        $verified = $this->body($this->jsonRequest('POST', '/merchant/auth/login/verify', null, ['ticket' => $step1['ticket'], 'code' => $code]));
        $this->assertSame(200, $this->jsonRequest('GET', '/merchant/auth/me', $verified['token'])->getStatusCode());
        $this->assertSame(401, $this->jsonRequest('POST', '/merchant/auth/login/verify', null, ['ticket' => $step1['ticket'], 'code' => $code])->getStatusCode(), 'ticket 只能用一次');

        // 自己关掉：只校验密码；再打开
        $this->jsonRequest('PUT', '/merchant/auth/sms-login', $verified['token'], ['enabled' => false, 'password' => self::PASSWORD]);
        $this->assertArrayHasKey('token', $this->merchantLogin($user->username));
        $this->jsonRequest('PUT', '/merchant/auth/sms-login', $verified['token'], ['enabled' => true, 'password' => self::PASSWORD]);

        // 平台关掉短信：只校验密码
        make(SettingService::class)->set(SmsService::SETTING_KEY, ['enabled' => false] + self::CONFIG);
        $this->assertArrayHasKey('token', $this->merchantLogin($user->username));

        // 平台解绑手机
        $admin = $this->loginAs($this->createAdminWithPermissions(['merchant.view', 'merchant.manage']));
        $users = $this->body($this->jsonRequest('GET', "/admin/merchants/{$user->merchant_id}/users", $admin));
        $this->assertSame($mobile, $users[0]['mobile']);
        $this->assertTrue($users[0]['sms_login_enabled']);
        $unbound = $this->body($this->jsonRequest('POST', "/admin/merchants/{$user->merchant_id}/users/{$user->id}/unbind-mobile", $admin));
        $this->assertSame([null, false], [$unbound['mobile'], $unbound['sms_login_enabled']], '解绑同时关掉登录短信验证');
    }

    public function testWrongCodesInvalidateTheCode()
    {
        make(SettingService::class)->set(SmsService::SETTING_KEY, self::CONFIG);
        $user = $this->createMerchantUser($this->createMerchant(), $this->mobile());
        $ticket = $this->merchantLogin($user->username)['ticket'];
        $code = $this->lastCode();
        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame(422, $this->jsonRequest('POST', '/merchant/auth/login/verify', null, ['ticket' => $ticket, 'code' => $this->wrong($code)])->getStatusCode());
        }
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/auth/login/verify', null, ['ticket' => $ticket, 'code' => $code])->getStatusCode(), '错 5 次后正确的也不行了');
    }

    public function testBalanceAlertOncePerDropAndRearmsAfterRecharge()
    {
        make(SettingService::class)->set(SmsService::SETTING_KEY, self::CONFIG);
        $merchant = $this->createMerchant('50.00');
        $mobile = $this->mobile();
        $user = $this->createMerchantUser($merchant, $mobile);
        $this->createMerchantUser($merchant, $mobile);
        $token = $this->merchantLogin($user->username)['token'] ?? null;
        // 绑定了手机要验证码：直接签发 token 走后面的接口
        $token ??= $this->verifyLatest($user);

        $this->assertSame(422, $this->jsonRequest('PUT', '/merchant/account/balance-alert', $token, ['threshold' => '-1'])->getStatusCode());
        $this->assertSame('100.00', $this->body($this->jsonRequest('PUT', '/merchant/account/balance-alert', $token, ['threshold' => '100']))['balance_alert_threshold']);
        $account = $this->body($this->jsonRequest('GET', '/merchant/account', $token));
        $this->assertSame('100.00', $account['balance_alert_threshold']);
        $this->assertSame([substr($mobile, 0, 3) . '****' . substr($mobile, -4)], $account['alert_mobiles'], '同一个手机号只发一条');

        // 余额够的、没绑手机的不发
        $rich = $this->createMerchant('500.00');
        Merchant::where('id', $rich->id)->update(['balance_alert_threshold' => '100.00']);
        $this->createMerchantUser($rich, $this->mobile());
        $noMobile = $this->createMerchant('10.00');
        Merchant::where('id', $noMobile->id)->update(['balance_alert_threshold' => '100.00']);
        $this->createMerchantUser($noMobile);

        $this->http->requests = [];
        make(MerchantBalanceAlertService::class)->run();
        $alerts = $this->alertsTo([$mobile]);
        $this->assertCount(1, $alerts);
        $this->assertSame(['name' => $merchant->name, 'balance' => '50.00', 'threshold' => '100.00'], json_decode($alerts[0]['TemplateParam'], true));
        $this->assertSame(date('Y-m-d'), Merchant::find($merchant->id)->balance_alert_date->toDateString());
        $this->assertNull(Merchant::find($noMobile->id)->balance_alert_date);
        $this->assertNull(Merchant::find($rich->id)->balance_alert_date);

        // 一直在阈值以下：不再发
        $alert = make(MerchantBalanceAlertService::class);
        $alert->run();
        $this->assertCount(1, $this->alertsTo([$mobile]));

        // 充值回到阈值以上、又跌破：再提醒，直到当天上限
        for ($i = 2; $i <= MerchantBalanceAlertService::DAILY_LIMIT + 1; ++$i) {
            Merchant::where('id', $merchant->id)->update(['balance' => '150.00']);
            $alert->run();
            $this->assertTrue(Merchant::find($merchant->id)->balance_alert_armed, '回到阈值以上重新开启');
            Merchant::where('id', $merchant->id)->update(['balance' => '80.00']);
            $alert->run();
            $this->assertCount(min($i, MerchantBalanceAlertService::DAILY_LIMIT), $this->alertsTo([$mobile]), "第 {$i} 次跌破");
        }
        $this->assertSame(MerchantBalanceAlertService::DAILY_LIMIT, Merchant::find($merchant->id)->balance_alert_count);

        // 第二天上限重新算
        Merchant::where('id', $merchant->id)->update(['balance_alert_date' => date('Y-m-d', strtotime('-1 day'))]);
        $alert->run();
        $this->assertCount(MerchantBalanceAlertService::DAILY_LIMIT + 1, $this->alertsTo([$mobile]));
        $this->assertSame(1, Merchant::find($merchant->id)->balance_alert_count);

        // 发送失败：还原，下一轮再试
        $other = $this->createMerchant('1.00');
        Merchant::where('id', $other->id)->update(['balance_alert_threshold' => '5.00']);
        $otherMobile = $this->mobile();
        $this->createMerchantUser($other, $otherMobile);
        $this->http->responses = [['status' => 200, 'body' => '{"Code":"isv.MOBILE_NUMBER_ILLEGAL","Message":"x"}']];
        make(MerchantBalanceAlertService::class)->run();
        $this->assertCount(1, $this->alertsTo([$otherMobile]));
        $failed = Merchant::find($other->id);
        $this->assertNull($failed->balance_alert_date);
        $this->assertTrue($failed->balance_alert_armed);
        $this->assertSame(0, $failed->balance_alert_count);
    }

    public function testMerchantListShowsTodayAndTotalConsumption()
    {
        $admin = $this->loginAs($this->createAdminWithPermissions(['merchant.view']));
        $merchant = $this->createMerchant();
        $this->createOrder($merchant, Order::STATUS_SUCCESS, '10.00', date('Y-m-d H:i:s', strtotime('-2 days')));
        $this->createOrder($merchant, Order::STATUS_SUCCESS, '20.00', date('Y-m-d H:i:s', strtotime('-1 day')));
        $this->createOrder($merchant, Order::STATUS_SUCCESS, '30.50');
        $this->createOrder($merchant, Order::STATUS_FAILED, '40.00');

        $row = fn () => $this->body($this->jsonRequest('GET', '/admin/merchants?keyword=' . urlencode($merchant->name), $admin))['data'][0];
        $this->assertSame(['30.50', '60.50'], [$row()['today_consumed'], $row()['total_consumed']], '还没结转过：全部实时算');

        $service = make(MerchantConsumptionService::class);
        $service->settle(date('Y-m-d', strtotime('-1 day')));
        $this->assertSame('10.00', Merchant::find($merchant->id)->consumed_total, '结转到昨天之前');
        $this->assertSame(['30.50', '60.50'], [$row()['today_consumed'], $row()['total_consumed']]);

        $service->settle();
        $service->settle();
        $this->assertSame('30.00', Merchant::find($merchant->id)->consumed_total, '重复结转不重复加');
        $this->assertSame(['30.50', '60.50'], [$row()['today_consumed'], $row()['total_consumed']]);

        $this->assertSame([], $this->body($this->jsonRequest('GET', '/admin/merchants?keyword=no_such_merchant_x', $admin))['data']);
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
     * @param list<string> $mobiles
     * @return list<array<string, string>>
     */
    private function alertsTo(array $mobiles): array
    {
        return array_values(array_map(static fn ($r) => $r['payload'], array_filter(
            $this->http->requests,
            static fn ($r) => in_array($r['payload']['PhoneNumbers'] ?? null, $mobiles, true) && $r['payload']['TemplateCode'] === 'SMS_100002'
        )));
    }

    private function verifyLatest(MerchantUser $user): string
    {
        $step1 = $this->merchantLogin($user->username);

        return $this->body($this->jsonRequest('POST', '/merchant/auth/login/verify', null, ['ticket' => $step1['ticket'], 'code' => $this->lastCode()]))['token'];
    }

    /**
     * 传了手机号就同时开启登录短信验证。
     */
    private function createMerchantUser(Merchant $merchant, ?string $mobile = null): MerchantUser
    {
        return MerchantUser::create([
            'merchant_id' => $merchant->id,
            'username' => $this->uniq('mu_'),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'mobile' => $mobile,
            'sms_login_enabled' => $mobile !== null,
            'status' => 'active',
        ]);
    }

    private function createOrder(Merchant $merchant, string $status, string $price, ?string $createdAt = null): Order
    {
        $order = Order::create([
            'order_no' => date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => $this->uniq('M'),
            'product_id' => 0,
            'product_code' => 'P',
            'product_name' => '话费',
            'mobile' => '1009' . random_int(1000000, 9999999),
            'operator' => 'cmcc',
            'province' => '广东',
            'face_value' => 100,
            'sale_price' => $price,
            'cost_price' => '1.00',
            'status' => $status,
            'notify_status' => 'none',
        ]);
        if ($createdAt !== null) {
            Order::where('id', $order->id)->update(['created_at' => $createdAt]);
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function merchantLogin(string $username): array
    {
        return $this->body($this->client->request('POST', '/merchant/auth/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['username' => $username, 'password' => self::PASSWORD],
        ]));
    }
}
