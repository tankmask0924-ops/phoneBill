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

namespace HyperfTest\Cases\Funding;

use App\Model\AlipayAccount;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantOnlinePayment;
use App\Model\MerchantUser;
use App\Model\SystemSetting;
use App\Network\HttpClient;
use App\Payment\AlipayClient;
use App\Security\Encrypter;
use App\Service\Setting\SettingService;
use App\Service\Storage\ObjectStorageService;
use App\Support\RedisLock;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\Cases\Admin\CreatesAdmins;
use HyperfTest\Cases\Admin\CreatesCatalog;
use HyperfTest\HttpTestCase;
use HyperfTest\Support\FakeHttpClient;
use RuntimeException;

use function Hyperf\Support\make;

/**
 * 支付宝转账加款：客户端签名验签、收款账户（多个，带固定收款码）和商户指定、商户提交订单号和金额查到账入账、
 * 重新查询 / 取消、后台查找到账记录确认入账。
 *
 * @internal
 * @coversNothing
 */
class AlipayTransferTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

    private const APP_ID = '2021000000000001';

    /** 1x1 的 PNG */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var null|array{app_private: string, app_public: string, alipay_private: string, alipay_public: string} */
    private static ?array $keys = null;

    private FakeHttpClient $http;

    /** @var list<int> */
    private array $accountIds = [];

    private ?string $originalStorage = null;

    private bool $hadStorage = false;

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->http = new FakeHttpClient();
        ApplicationContext::getContainer()->set(HttpClient::class, $this->http);
        // 测试里不抢锁、不限频
        ApplicationContext::getContainer()->set(RedisLock::class, new class extends RedisLock {
            public function acquire(string $key, int $ttlSeconds): ?string
            {
                return 'test';
            }

            public function release(string $key, string $token): void
            {
            }

            public function once(string $key, int $ttlSeconds): bool
            {
                return true;
            }
        });
        $this->client = make(Client::class);
        self::$keys ??= self::generateKeys();
        $row = SystemSetting::where('key', ObjectStorageService::SETTING_KEY)->first();
        $this->hadStorage = $row !== null;
        $this->originalStorage = $row?->value;
        make(SettingService::class)->set(ObjectStorageService::SETTING_KEY, [
            'secret_id' => 'AKID_TEST', 'secret_key' => 'SK_TEST', 'region' => 'ap-guangzhou', 'bucket' => 'pb-1250000000', 'path_prefix' => 'test',
        ]);
    }

    protected function tearDown(): void
    {
        MerchantOnlinePayment::whereIn('merchant_id', $this->merchantIds)->delete();
        AlipayAccount::destroy($this->accountIds);
        if ($this->hadStorage) {
            SystemSetting::where('key', ObjectStorageService::SETTING_KEY)->update(['value' => $this->originalStorage]);
        } else {
            SystemSetting::where('key', ObjectStorageService::SETTING_KEY)->delete();
        }
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testClientSignsRequestsAndVerifiesResponses()
    {
        $client = new AlipayClient($this->http, self::APP_ID, self::$keys['app_private'], self::$keys['alipay_public']);
        $this->http->responses = [$this->alipayResponse(['code' => '10000', 'msg' => 'Success', 'page_no' => '1', 'page_size' => '2000', 'total_size' => '1', 'detail_list' => [
            ['account_log_id' => '1001', 'trans_amount' => '1.00', 'direction' => '收入', 'trans_dt' => '2026-10-19 10:00:00'],
        ]])];
        $items = $client->accountLogs('2026-10-19 09:00:00', '2026-10-19 11:00:00');
        $this->assertSame('1001', $items[0]['account_log_id']);

        $sent = $this->http->requests[0];
        $this->assertSame('POST_FORM', $sent['method']);
        $this->assertSame('https://openapi.alipay.com/gateway.do?charset=utf-8', $sent['url']);
        $form = $sent['payload'];
        $this->assertSame(['alipay.data.bill.accountlog.query', self::APP_ID, 'RSA2', 'JSON', '1.0'], [$form['method'], $form['app_id'], $form['sign_type'], $form['format'], $form['version']]);
        $this->assertSame(['start_time' => '2026-10-19 09:00:00', 'end_time' => '2026-10-19 11:00:00', 'page_no' => '1', 'page_size' => '2000'], json_decode($form['biz_content'], true));
        $this->assertTrue(AlipayClient::verify(AlipayClient::signContent($form), $form['sign'], self::$keys['app_public']), '用应用私钥签名');
        // 按支付宝订单号查
        $this->http->responses = [$this->accountLogs([])];
        $client->accountLogs('2026-10-19 09:00:00', '2026-10-19 11:00:00', '2026101922001400000000000001');
        $this->assertSame('2026101922001400000000000001', json_decode($this->http->requests[1]['payload']['biz_content'], true)['alipay_order_no']);
        $this->assertSame('a=1&b=2', AlipayClient::signContent(['b' => '2', 'sign' => 'x', 'empty' => '', 'a' => '1']));

        // 响应被改过、错误码、不带签名的错误
        $tampered = $this->alipayResponse(['code' => '10000', 'total_size' => '0', 'detail_list' => []]);
        $tampered['body'] = str_replace('"total_size":"0"', '"total_size":"9"', $tampered['body']);
        $this->http->responses = [$tampered];
        $this->assertThrowsMessage('验签失败', fn () => $client->accountLogs('2026-10-19 09:00:00', '2026-10-19 11:00:00'));
        $this->http->responses = [$this->alipayResponse(['code' => '40004', 'msg' => 'Business Failed', 'sub_code' => 'isv.insufficient-isv-permissions', 'sub_msg' => 'ISV权限不足'])];
        $this->assertThrowsMessage('ISV权限不足', fn () => $client->accountLogs('2026-10-19 09:00:00', '2026-10-19 11:00:00'));
        $this->http->responses = [['status' => 200, 'body' => '{"error_response":{"code":"40002","msg":"Invalid Arguments","sub_code":"isv.invalid-app-id","sub_msg":"无效的AppID参数"}}']];
        $this->assertThrowsMessage('无效的AppID参数', fn () => $client->accountLogs('2026-10-19 09:00:00', '2026-10-19 11:00:00'));
        $this->http->responses = [['status' => 502, 'body' => 'Bad Gateway']];
        $this->assertThrowsMessage('HTTP 502', fn () => $client->accountLogs('2026-10-19 09:00:00', '2026-10-19 11:00:00'));

        // 私钥带 PEM 头尾、PKCS1 都能用
        $this->assertTrue(AlipayClient::isPrivateKey(self::$keys['app_private']));
        $this->assertTrue(AlipayClient::isPrivateKey(self::pemBody(self::$keys['app_private'])));
        $this->assertFalse(AlipayClient::isPrivateKey('not-a-key'));
        $this->assertTrue(AlipayClient::isPublicKey(self::pemBody(self::$keys['alipay_public'])));
    }

    public function testAccountsAndMerchantBinding()
    {
        $admin = $this->loginAs($this->createAdminWithPermissions(['setting.manage', 'funding.view', 'funding.config']));
        $valid = $this->accountData() + ['qr_code' => $this->uploadQrCode($admin)['key']];

        foreach ([['app_id' => 'abc'], ['alipay_public_key' => 'bad'], ['private_key' => 'bad'], ['private_key' => ''], ['account' => 'abc'], ['min_amount' => '100', 'max_amount' => '10'], ['name' => ''], ['qr_code' => ''], ['qr_code' => 'test/vouchers/1/x.png']] as $bad) {
            $this->assertSame(422, $this->jsonRequest('POST', '/admin/alipay-accounts', $admin, $bad + $valid)->getStatusCode(), json_encode($bad));
        }
        $first = $this->body($this->jsonRequest('POST', '/admin/alipay-accounts', $admin, $valid));
        $second = $this->body($this->jsonRequest('POST', '/admin/alipay-accounts', $admin, ['name' => '备用账户', 'account' => '13800138000'] + $valid));
        $this->accountIds = [$first['id'], $second['id']];
        $this->assertArrayNotHasKey('private_key', $first);
        $this->assertSame(['测试收款账户', 'pay@example.com', '张三', $valid['qr_code'], '1.00', '5000.00', 'active'], [$first['name'], $first['account'], $first['account_name'], $first['qr_code'], $first['min_amount'], $first['max_amount'], $first['status']]);
        $this->assertStringStartsWith('test/alipay-qr/', $first['qr_code']);
        $this->assertNotSame(trim(self::$keys['app_private']), AlipayAccount::find($first['id'])->private_key, '私钥加密存储');

        // 私钥、收款码留空不改；换一张收款码
        $updated = $this->body($this->jsonRequest('PUT', "/admin/alipay-accounts/{$first['id']}", $admin, ['private_key' => '', 'qr_code' => '', 'max_amount' => '8000'] + $valid));
        $this->assertSame(['8000.00', $valid['qr_code']], [$updated['max_amount'], $updated['qr_code']]);
        $this->assertSame(trim(self::$keys['app_private']), make(Encrypter::class)->decrypt(AlipayAccount::find($first['id'])->private_key));
        $newQr = $this->uploadQrCode($admin)['key'];
        $this->assertSame($newQr, $this->body($this->jsonRequest('PUT', "/admin/alipay-accounts/{$first['id']}", $admin, ['qr_code' => $newQr] + $valid))['qr_code']);
        $this->assertSame('disabled', $this->body($this->jsonRequest('POST', "/admin/alipay-accounts/{$second['id']}/status", $admin, ['status' => 'disabled']))['status']);

        // 测试连接：用这个账户查最近一小时的账务明细
        $this->http->requests = [];
        $this->http->responses = [$this->accountLogs([])];
        $ok = $this->body($this->jsonRequest('POST', "/admin/alipay-accounts/{$first['id']}/test", $admin));
        $this->assertTrue($ok['success'], $ok['message']);
        $this->assertSame(self::APP_ID, $this->http->requests[0]['payload']['app_id']);
        $this->http->responses = [$this->alipayResponse(['code' => '40004', 'msg' => 'Business Failed', 'sub_msg' => 'ISV权限不足'])];
        $failed = $this->body($this->jsonRequest('POST', "/admin/alipay-accounts/{$first['id']}/test", $admin));
        $this->assertFalse($failed['success']);
        $this->assertStringContainsString('ISV权限不足', $failed['message']);

        // 商户指定账户：一个商户一个，可以清空
        $merchant = $this->createMerchant();
        $this->assertNull($this->body($this->jsonRequest('GET', "/admin/merchants/{$merchant->id}/alipay-account", $admin))['alipay_account_id']);
        $this->assertSame(422, $this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/alipay-account", $admin, ['alipay_account_id' => 999999999])->getStatusCode());
        $bound = $this->body($this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/alipay-account", $admin, ['alipay_account_id' => $first['id']]));
        $this->assertSame($first['id'], $bound['alipay_account_id']);
        $this->assertContains(['id' => $second['id'], 'name' => '备用账户', 'account' => '13800138000', 'status' => 'disabled'], $bound['accounts']);
        $listed = array_column($this->body($this->jsonRequest('GET', '/admin/alipay-accounts', $admin)), null, 'id');
        $this->assertSame([$merchant->name], $listed[$first['id']]['merchant_names']);
        $this->assertStringContainsString($newQr, (string) $listed[$first['id']]['qr_code_url']);
        $this->assertNull($this->body($this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/alipay-account", $admin, ['alipay_account_id' => null]))['alipay_account_id']);

        $viewer = $this->loginAs($this->createAdminWithPermissions(['funding.view']));
        $this->assertSame(403, $this->jsonRequest('GET', '/admin/alipay-accounts', $viewer)->getStatusCode());
        $this->assertSame(403, $this->upload($viewer, '/admin/alipay-accounts/qr-code')->getStatusCode());
        $this->assertSame(403, $this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/alipay-account", $viewer, ['alipay_account_id' => $first['id']])->getStatusCode());
    }

    public function testMerchantSubmitsTransferAndIsCreditedAutomatically()
    {
        $mine = $this->createMerchant('10.00');
        $token = $this->merchantLogin($mine);
        $theirs = $this->createMerchant();
        $theirToken = $this->merchantLogin($theirs);
        $first = '2026102622001400000000000001';

        // 没给这个商户指定账户
        $account = $this->createAccount();
        $this->assertFalse($this->body($this->jsonRequest('GET', '/merchant/online-payments/options', $token))['enabled']);
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/online-payments', $token, ['alipay_order_no' => $first, 'amount' => '100'])->getStatusCode());

        $this->bind($mine, $account);
        $this->bind($theirs, $account);
        $options = $this->body($this->jsonRequest('GET', '/merchant/online-payments/options', $token));
        $this->assertSame([true, '1.00', '5000.00', 'pay@example.com', '张三'], [$options['enabled'], $options['min_amount'], $options['max_amount'], $options['receive_account'], $options['receive_name']]);
        $this->assertStringContainsString('alipay-qr/', (string) $options['qr_code_url']);
        foreach ([['alipay_order_no' => 'abc'], ['alipay_order_no' => ''], ['amount' => '1.234'], ['amount' => '0.5'], ['amount' => '9000']] as $bad) {
            $this->assertSame(422, $this->jsonRequest('POST', '/merchant/online-payments', $token, $bad + ['alipay_order_no' => $first, 'amount' => '100'])->getStatusCode(), json_encode($bad));
        }

        // 按订单号查到收入、金额相等：立即入账；别的订单号的流水、支出不算
        $now = date('Y-m-d H:i:s');
        $this->http->requests = [];
        $this->http->responses = [$this->accountLogs([
            ['account_log_id' => 'L-OTHER', 'trans_amount' => '100.00', 'direction' => '收入', 'trans_dt' => $now, 'alipay_order_no' => '2026102622001499999999999999'],
            ['account_log_id' => 'L-OUT', 'trans_amount' => '-100.00', 'direction' => '支出', 'trans_dt' => $now, 'alipay_order_no' => $first],
            ['account_log_id' => 'L-OK', 'trans_amount' => '100.00', 'direction' => '收入', 'trans_dt' => $now, 'alipay_order_no' => $first, 'other_account' => 'pay***@qq.com', 'trans_memo' => '转账'],
        ])];
        $p = $this->body($this->jsonRequest('POST', '/merchant/online-payments', $token, ['alipay_order_no' => " {$first} ", 'amount' => '100']));
        $this->assertSame(['paid', $first, '100.00', '100.00', null], [$p['status'], $p['alipay_order_no'], $p['amount'], $p['pay_amount'], $p['check_message']]);
        $this->assertMatchesRegularExpression('/^AT\d{20}$/', $p['order_no']);
        $this->assertArrayNotHasKey('account_log_id', $p);
        $biz = json_decode($this->http->requests[0]['payload']['biz_content'], true);
        $this->assertSame($first, $biz['alipay_order_no']);
        $this->assertGreaterThanOrEqual(date('Y-m-d H:i:s', time() - 30 * 86400 - 5), $biz['start_time'], '最多往前查 30 天');
        $this->assertLessThanOrEqual(date('Y-m-d H:i:s', time() - 86400), $biz['start_time'], '至少往前查一天');
        $paid = MerchantOnlinePayment::find($p['id']);
        $this->assertSame([$account, MerchantOnlinePayment::CONFIRM_AUTO, 'L-OK', 'pay***@qq.com'], [$paid->alipay_account_id, $paid->confirm_type, $paid->account_log_id, $paid->payer_account]);
        $this->assertSame('110.00', Merchant::find($mine->id)->balance);
        $log = MerchantBalanceLog::find($paid->balance_log_id);
        $this->assertSame([MerchantBalanceLog::TYPE_RECHARGE, '100.00', "支付宝转账 {$p['order_no']} 自动到账"], [$log->type, $log->amount, $log->remark]);

        // 同一个订单号不能再提交（自己、别人都不行）
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/online-payments', $token, ['alipay_order_no' => $first, 'amount' => '100'])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/online-payments', $theirToken, ['alipay_order_no' => $first, 'amount' => '100'])->getStatusCode());

        // 查不到：记成未到账，告诉商户原因；别的商户看不到
        $second = '2026102622001400000000000002';
        $this->http->responses = [$this->accountLogs([])];
        $pending = $this->body($this->jsonRequest('POST', '/merchant/online-payments', $token, ['alipay_order_no' => $second, 'amount' => '50']));
        $this->assertSame('pending', $pending['status']);
        $this->assertStringContainsString('暂未查到', $pending['check_message']);
        $this->assertNotNull($pending['checked_at']);
        $this->assertSame(404, $this->jsonRequest('GET', "/merchant/online-payments/{$pending['id']}", $theirToken)->getStatusCode());
        $this->assertSame([], $this->body($this->jsonRequest('GET', '/merchant/online-payments', $theirToken))['data']);
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/online-payments', $theirToken, ['alipay_order_no' => $second, 'amount' => '50'])->getStatusCode(), '别的商户提交过的订单号');

        // 重新查询：金额不符不入账
        $this->http->responses = [$this->accountLogs([['account_log_id' => 'L-2', 'trans_amount' => '49.00', 'direction' => '收入', 'trans_dt' => $now, 'alipay_order_no' => $second]])];
        $checked = $this->body($this->jsonRequest('POST', "/merchant/online-payments/{$pending['id']}/check", $token));
        $this->assertSame('pending', $checked['status']);
        $this->assertStringContainsString('金额不一致', $checked['check_message']);

        // 改成正确的金额再提交同一个订单号：还是这笔单，入账
        $this->http->responses = [$this->accountLogs([['account_log_id' => 'L-2', 'trans_amount' => '49.00', 'direction' => '收入', 'trans_dt' => $now, 'alipay_order_no' => $second]])];
        $fixed = $this->body($this->jsonRequest('POST', '/merchant/online-payments', $token, ['alipay_order_no' => $second, 'amount' => '49']));
        $this->assertSame([$pending['id'], 'paid', '49.00'], [$fixed['id'], $fixed['status'], $fixed['pay_amount']]);
        $this->assertSame('159.00', Merchant::find($mine->id)->balance);
        $this->assertSame('paid', $this->body($this->jsonRequest('POST', "/merchant/online-payments/{$pending['id']}/check", $token))['status'], '已到账的不再查');

        // 流水已经入过账的不认；查询失败
        $third = '2026102622001400000000000003';
        $this->http->responses = [$this->accountLogs([['account_log_id' => 'L-OK', 'trans_amount' => '20.00', 'direction' => '收入', 'trans_dt' => $now, 'alipay_order_no' => $third]])];
        $this->assertStringContainsString('已经入过账', $this->body($this->jsonRequest('POST', '/merchant/online-payments', $token, ['alipay_order_no' => $third, 'amount' => '20']))['check_message']);
        $fourth = '2026102622001400000000000004';
        $this->http->responses = [$this->alipayResponse(['code' => '40004', 'msg' => 'Business Failed', 'sub_msg' => 'ISV权限不足'])];
        $failed = $this->body($this->jsonRequest('POST', '/merchant/online-payments', $token, ['alipay_order_no' => $fourth, 'amount' => '20']));
        $this->assertSame('pending', $failed['status']);
        $this->assertStringContainsString('查询支付宝失败', $failed['check_message']);

        // 取消；取消后再提交同一个订单号重新打开这笔单
        $this->assertSame('cancelled', $this->body($this->jsonRequest('POST', "/merchant/online-payments/{$failed['id']}/cancel", $token))['status']);
        $this->assertSame(422, $this->jsonRequest('POST', "/merchant/online-payments/{$p['id']}/cancel", $token)->getStatusCode(), '已到账不能取消');
        $this->http->responses = [$this->accountLogs([])];
        $reopened = $this->body($this->jsonRequest('POST', '/merchant/online-payments', $token, ['alipay_order_no' => $fourth, 'amount' => '30']));
        $this->assertSame([$failed['id'], 'pending', '30.00'], [$reopened['id'], $reopened['status'], $reopened['amount']]);

        $this->assertSame([$pending['id'], $p['id']], array_column($this->body($this->jsonRequest('GET', '/merchant/online-payments?status=paid', $token))['data'], 'id'));
        $this->assertSame([], $this->body($this->jsonRequest('GET', '/merchant/online-payments?status=expired', $token))['data']);

        // 最多 5 笔未到账
        for ($i = 5; $i <= 7; ++$i) {
            $this->assertSame(200, $this->jsonRequest('POST', '/merchant/online-payments', $token, ['alipay_order_no' => "202610262200140000000000000{$i}", 'amount' => '10'])->getStatusCode());
        }
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/online-payments', $token, ['alipay_order_no' => '2026102622001400000000000008', 'amount' => '10'])->getStatusCode());

        // 账户停用：不能再提交，已提交的还能重新查询
        AlipayAccount::where('id', $account)->update(['status' => AlipayAccount::STATUS_DISABLED]);
        $this->assertFalse($this->body($this->jsonRequest('GET', '/merchant/online-payments/options', $token))['enabled']);
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/online-payments', $token, ['alipay_order_no' => '2026102622001400000000000009', 'amount' => '10'])->getStatusCode());
        $this->http->responses = [$this->accountLogs([['account_log_id' => 'L-4', 'trans_amount' => '30.00', 'direction' => '收入', 'trans_dt' => $now, 'alipay_order_no' => $fourth]])];
        $this->assertSame('paid', $this->body($this->jsonRequest('POST', "/merchant/online-payments/{$failed['id']}/check", $token))['status']);
    }

    public function testAdminConfirm()
    {
        $merchant = $this->createMerchant('0.00');
        $account = $this->createAccount();
        $this->bind($merchant, $account);
        $token = $this->merchantLogin($merchant);
        $viewer = $this->loginAs($this->createAdminWithPermissions(['funding.view']));
        $reviewer = $this->loginAs($this->createAdminWithPermissions(['funding.view', 'funding.review']));
        $alipayOrderNo = '2026102622001400000000000011';

        $this->http->responses = [$this->accountLogs([])];
        $p = $this->body($this->jsonRequest('POST', '/merchant/online-payments', $token, ['alipay_order_no' => $alipayOrderNo, 'amount' => '90']));
        $this->assertSame('pending', $p['status']);
        // 改版前过期的单：没有支付宝订单号，按金额找到账记录
        $old = MerchantOnlinePayment::create([
            'order_no' => 'AT' . date('YmdHis') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'merchant_id' => $merchant->id, 'alipay_account_id' => $account, 'amount' => '20.00', 'pay_amount' => '20.37',
            'receive_account' => 'pay@example.com', 'receive_name' => '张三', 'status' => MerchantOnlinePayment::STATUS_EXPIRED,
        ]);

        $list = $this->body($this->jsonRequest('GET', "/admin/funding/online-payments?keyword={$alipayOrderNo}", $viewer));
        $this->assertSame([$p['id']], array_column($list['data'], 'id'));
        $this->assertStringContainsString('暂未查到', $list['data'][0]['check_message']);
        $this->assertSame([], $this->body($this->jsonRequest('GET', '/admin/funding/online-payments?keyword=AT00000000000000000000', $viewer))['data']);
        $this->assertSame(403, $this->jsonRequest('POST', "/admin/funding/online-payments/{$p['id']}/candidates", $viewer)->getStatusCode());

        // 填了订单号的单：只列这个订单号的流水，金额不一样的也列出来
        $now = date('Y-m-d H:i:s');
        $logs = $this->accountLogs([
            ['account_log_id' => 'C-1', 'trans_amount' => '88.00', 'direction' => '收入', 'trans_dt' => $now, 'alipay_order_no' => $alipayOrderNo],
            ['account_log_id' => 'C-X', 'trans_amount' => '90.00', 'direction' => '收入', 'trans_dt' => $now, 'alipay_order_no' => '2026102622001499999999999999'],
        ]);
        $this->http->responses = [$logs];
        $this->assertSame(['C-1'], array_column($this->body($this->jsonRequest('POST', "/admin/funding/online-payments/{$p['id']}/candidates", $reviewer)), 'account_log_id'));

        $this->http->responses = [$logs];
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/funding/online-payments/{$p['id']}/confirm", $reviewer, ['account_log_id' => 'C-X'])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/funding/online-payments/{$p['id']}/confirm", $reviewer, [])->getStatusCode(), '不选流水必须写备注');
        $this->assertSame(403, $this->jsonRequest('POST', "/admin/funding/online-payments/{$p['id']}/confirm", $viewer, ['remark' => 'x'])->getStatusCode());

        // 按流水确认：按流水的金额入账
        $this->http->responses = [$logs];
        $confirmed = $this->body($this->jsonRequest('POST', "/admin/funding/online-payments/{$p['id']}/confirm", $reviewer, ['account_log_id' => 'C-1', 'remark' => '商户金额填错']));
        $this->assertSame(['paid', 'manual', 'C-1', '88.00', null], [$confirmed['status'], $confirmed['confirm_type'], $confirmed['account_log_id'], $confirmed['pay_amount'], $confirmed['check_message']]);
        $this->assertNotNull($confirmed['confirmer_name']);
        $this->assertSame('88.00', Merchant::find($merchant->id)->balance);
        $this->assertSame("支付宝转账 {$p['order_no']} 后台确认入账", MerchantBalanceLog::find($confirmed['balance_log_id'])->remark);
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/funding/online-payments/{$p['id']}/confirm", $reviewer, ['remark' => '再来一次'])->getStatusCode());

        // 改版前的单：金额相等、下单之后、没入过账的收入；备注是这单单号的排前面
        $later = date('Y-m-d H:i:s', time() + 5);
        $this->http->responses = [$this->accountLogs([
            ['account_log_id' => 'O-1', 'trans_amount' => '20.37', 'direction' => '收入', 'trans_dt' => $now],
            ['account_log_id' => 'O-2', 'trans_amount' => '20.37', 'direction' => '收入', 'trans_dt' => $later, 'trans_memo' => $old->order_no],
            ['account_log_id' => 'O-EARLY', 'trans_amount' => '20.37', 'direction' => '收入', 'trans_dt' => date('Y-m-d H:i:s', time() - 7200)],
            ['account_log_id' => 'O-AMOUNT', 'trans_amount' => '20.00', 'direction' => '收入', 'trans_dt' => $later],
        ])];
        $candidates = $this->body($this->jsonRequest('POST', "/admin/funding/online-payments/{$old->id}/candidates", $reviewer));
        $this->assertSame(['O-2', 'O-1'], array_column($candidates, 'account_log_id'));
        $this->assertSame([true, false], array_column($candidates, 'memo_matches'));

        // 不带流水直接确认（写了备注）
        $direct = $this->body($this->jsonRequest('POST', "/admin/funding/online-payments/{$old->id}/confirm", $reviewer, ['remark' => '支付宝 App 里核实到账']));
        $this->assertSame(['paid', null, '支付宝 App 里核实到账', '20.37'], [$direct['status'], $direct['account_log_id'], $direct['remark'], $direct['pay_amount']]);
        $this->assertSame('108.37', Merchant::find($merchant->id)->balance);
    }

    /**
     * @return array<string, mixed>
     */
    private function accountData(string $appId = self::APP_ID): array
    {
        return [
            'name' => '测试收款账户',
            'app_id' => $appId,
            'private_key' => self::$keys['app_private'],
            'alipay_public_key' => self::pemBody(self::$keys['alipay_public']),
            'account' => 'pay@example.com',
            'account_name' => '张三',
            'min_amount' => '1',
            'max_amount' => '5000',
            'remark' => '',
        ];
    }

    private function createAccount(string $appId = self::APP_ID): int
    {
        $admin = $this->loginAs($this->createAdminWithPermissions(['setting.manage']));
        $data = $this->accountData($appId) + ['qr_code' => $this->uploadQrCode($admin)['key']];
        $account = $this->body($this->jsonRequest('POST', '/admin/alipay-accounts', $admin, $data));
        $this->accountIds[] = $account['id'];

        return $account['id'];
    }

    /**
     * @return array{key: string, url: string}
     */
    private function uploadQrCode(?string $admin): array
    {
        $this->http->responses = [['status' => 200, 'body' => '']];
        $response = $this->upload($admin, '/admin/alipay-accounts/qr-code');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->body($response);
    }

    private function upload(?string $token, string $path)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, (string) base64_decode(self::PNG));
        rewind($stream);

        return $this->client->request('POST', $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'multipart' => [['name' => 'file', 'contents' => $stream, 'filename' => 'qr.png']],
        ]);
    }

    private function bind(Merchant $merchant, int $accountId): void
    {
        $admin = $this->loginAs($this->createAdminWithPermissions(['funding.config']));
        $this->assertSame(200, $this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/alipay-account", $admin, ['alipay_account_id' => $accountId])->getStatusCode());
    }

    /**
     * @param list<array<string, string>> $items
     * @return array{status: int, body: string}
     */
    private function accountLogs(array $items): array
    {
        return $this->alipayResponse(['code' => '10000', 'msg' => 'Success', 'page_no' => '1', 'page_size' => '2000', 'total_size' => (string) count($items), 'detail_list' => $items]);
    }

    /**
     * 模拟支付宝的响应：用「支付宝私钥」对 _response 节点签名。
     *
     * @param array<string, mixed> $data
     * @return array{status: int, body: string}
     */
    private function alipayResponse(array $data): array
    {
        $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        openssl_sign($json, $signature, self::$keys['alipay_private'], OPENSSL_ALGO_SHA256);

        return ['status' => 200, 'body' => '{"alipay_data_bill_accountlog_query_response":' . $json . ',"sign":"' . base64_encode($signature) . '"}'];
    }

    private function assertThrowsMessage(string $expected, callable $run): void
    {
        try {
            $run();
            $this->fail("应抛出包含「{$expected}」的异常");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    private function merchantLogin(Merchant $merchant): ?string
    {
        $user = MerchantUser::create([
            'merchant_id' => $merchant->id,
            'username' => $this->uniq('mu_'),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => 'active',
        ]);
        $response = $this->client->request('POST', '/merchant/auth/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['username' => $user->username, 'password' => self::PASSWORD],
        ]);

        return $this->body($response)['token'] ?? null;
    }

    private static function pemBody(string $pem): string
    {
        return (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    }

    /**
     * @return array{app_private: string, app_public: string, alipay_private: string, alipay_public: string}
     */
    private static function generateKeys(): array
    {
        $keys = [];
        foreach (['app', 'alipay'] as $who) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            $keys[$who . '_private'] = $private;
            $keys[$who . '_public'] = openssl_pkey_get_details($key)['key'];
        }

        return $keys;
    }
}
