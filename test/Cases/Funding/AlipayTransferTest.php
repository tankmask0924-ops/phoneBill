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
use App\Network\HttpClient;
use App\Payment\AlipayClient;
use App\Security\Encrypter;
use App\Service\Payment\AlipayTransferService;
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
 * 支付宝扫码转账加款：客户端签名验签、收款账户（多个）和商户指定、商户发起 / 取消、按账户自动核对入账、过期、
 * 后台查找到账记录确认入账。
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

    /** @var null|array{app_private: string, app_public: string, alipay_private: string, alipay_public: string} */
    private static ?array $keys = null;

    private FakeHttpClient $http;

    /** @var list<int> */
    private array $accountIds = [];

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->http = new FakeHttpClient();
        ApplicationContext::getContainer()->set(HttpClient::class, $this->http);
        // 开发环境的定时任务也在跑核对，测试里不抢锁、不限频
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
    }

    protected function tearDown(): void
    {
        MerchantOnlinePayment::whereIn('merchant_id', $this->merchantIds)->delete();
        AlipayAccount::destroy($this->accountIds);
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
        $valid = $this->accountData();

        foreach ([['app_id' => 'abc'], ['alipay_public_key' => 'bad'], ['private_key' => 'bad'], ['private_key' => ''], ['account' => 'abc'], ['expire_minutes' => 2], ['min_amount' => '100', 'max_amount' => '10'], ['name' => '']] as $bad) {
            $this->assertSame(422, $this->jsonRequest('POST', '/admin/alipay-accounts', $admin, $bad + $valid)->getStatusCode(), json_encode($bad));
        }
        $first = $this->body($this->jsonRequest('POST', '/admin/alipay-accounts', $admin, $valid));
        $second = $this->body($this->jsonRequest('POST', '/admin/alipay-accounts', $admin, ['name' => '备用账户', 'account' => '13800138000'] + $valid));
        $this->accountIds = [$first['id'], $second['id']];
        $this->assertArrayNotHasKey('private_key', $first);
        $this->assertSame(['测试收款账户', 'pay@example.com', '张三', 15, '1.00', '5000.00', 'active'], [$first['name'], $first['account'], $first['account_name'], $first['expire_minutes'], $first['min_amount'], $first['max_amount'], $first['status']]);
        $this->assertNotSame(trim(self::$keys['app_private']), AlipayAccount::find($first['id'])->private_key, '私钥加密存储');

        // 私钥留空不改
        $updated = $this->body($this->jsonRequest('PUT', "/admin/alipay-accounts/{$first['id']}", $admin, ['private_key' => '', 'max_amount' => '8000'] + $valid));
        $this->assertSame('8000.00', $updated['max_amount']);
        $this->assertSame(trim(self::$keys['app_private']), make(Encrypter::class)->decrypt(AlipayAccount::find($first['id'])->private_key));
        $this->assertSame('disabled', $this->body($this->jsonRequest('POST', "/admin/alipay-accounts/{$second['id']}/status", $admin, ['status' => 'disabled']))['status']);

        // 测试连接：用这个账户查最近一小时的账务明细
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
        $this->assertNull($this->body($this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/alipay-account", $admin, ['alipay_account_id' => null]))['alipay_account_id']);

        $viewer = $this->loginAs($this->createAdminWithPermissions(['funding.view']));
        $this->assertSame(403, $this->jsonRequest('GET', '/admin/alipay-accounts', $viewer)->getStatusCode());
        $this->assertSame(403, $this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/alipay-account", $viewer, ['alipay_account_id' => $first['id']])->getStatusCode());
    }

    public function testMerchantPaysAndIsCreditedAutomatically()
    {
        $mine = $this->createMerchant('10.00');
        $token = $this->merchantLogin($mine);
        $theirToken = $this->merchantLogin($this->createMerchant());

        // 没给这个商户指定账户
        $account = $this->createAccount();
        $this->assertFalse($this->body($this->jsonRequest('GET', '/merchant/online-payments/options', $token))['enabled']);
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/online-payments', $token, ['amount' => '100'])->getStatusCode());

        $this->bind($mine, $account);
        $this->assertSame(['enabled' => true, 'min_amount' => '1.00', 'max_amount' => '5000.00', 'expire_minutes' => 15], $this->body($this->jsonRequest('GET', '/merchant/online-payments/options', $token)));
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/online-payments', $token, ['amount' => '0.5'])->getStatusCode(), '低于最低金额');
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/online-payments', $token, ['amount' => '1.234'])->getStatusCode());

        $p = $this->body($this->jsonRequest('POST', '/merchant/online-payments', $token, ['amount' => '100']));
        $this->assertSame('pending', $p['status']);
        $this->assertSame('100.00', $p['amount']);
        $this->assertSame(1, bccomp($p['pay_amount'], '100.00', 2), '应付金额加了随机分');
        $this->assertSame(-1, bccomp($p['pay_amount'], '101.00', 2));
        $this->assertMatchesRegularExpression('/^AT\d{20}$/', $p['order_no']);
        $this->assertSame('pay@example.com', $p['receive_account']);
        $this->assertSame('*三', $p['receive_name']);
        $this->assertStringStartsWith('alipays://platformapi/startapp?appId=20000200&actionType=toAccount', $p['qr_content']);
        $this->assertStringContainsString('&amount=' . $p['pay_amount'] . '&memo=' . $p['order_no'], $p['qr_content']);
        $this->assertArrayNotHasKey('account_log_id', $p);
        $this->assertSame(404, $this->jsonRequest('GET', "/merchant/online-payments/{$p['id']}", $theirToken)->getStatusCode());
        $this->assertSame([], $this->body($this->jsonRequest('GET', '/merchant/online-payments', $theirToken))['data']);

        // 同时待支付的单的应付金额不会重复
        $second = $this->body($this->jsonRequest('POST', '/merchant/online-payments', $token, ['amount' => '100']));
        $this->assertNotSame($p['pay_amount'], $second['pay_amount']);
        $this->jsonRequest('POST', '/merchant/online-payments', $token, ['amount' => '100']);
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/online-payments', $token, ['amount' => '100'])->getStatusCode(), '最多 3 笔待支付');

        // 核对：备注写的是别的支付单号不认；金额不对、支出不认；匹配的那条入账
        $now = date('Y-m-d H:i:s');
        $this->http->responses = [$this->accountLogs([
            ['account_log_id' => 'L-OTHER', 'trans_amount' => $p['pay_amount'], 'direction' => '收入', 'trans_dt' => $now, 'trans_memo' => 'AT20990101000000000000'],
            ['account_log_id' => 'L-OUT', 'trans_amount' => '-' . $second['pay_amount'], 'direction' => '支出', 'trans_dt' => $now],
            ['account_log_id' => 'L-WRONG', 'trans_amount' => '100.00', 'direction' => '收入', 'trans_dt' => $now],
            ['account_log_id' => 'L-OK', 'trans_amount' => $p['pay_amount'], 'direction' => '收入', 'trans_dt' => $now, 'other_account' => 'pay***@qq.com', 'alipay_order_no' => '2026101922001', 'trans_memo' => '转账'],
        ])];
        $this->assertSame(1, make(AlipayTransferService::class)->reconcile($account));
        $paid = MerchantOnlinePayment::find($p['id']);
        $this->assertSame($account, $paid->alipay_account_id);
        $this->assertSame([MerchantOnlinePayment::STATUS_PAID, MerchantOnlinePayment::CONFIRM_AUTO, 'L-OK', 'pay***@qq.com', null], [$paid->status, $paid->confirm_type, $paid->account_log_id, $paid->payer_account, $paid->active_amount]);
        $this->assertSame(bcadd('10.00', $p['pay_amount'], 2), Merchant::find($mine->id)->balance);
        $log = MerchantBalanceLog::find($paid->balance_log_id);
        $this->assertSame([MerchantBalanceLog::TYPE_RECHARGE, $p['pay_amount'], "支付宝转账 {$p['order_no']} 自动到账"], [$log->type, $log->amount, $log->remark]);
        $this->assertSame('pending', MerchantOnlinePayment::find($second['id'])->status);

        // 同一条流水不会再入账
        $this->http->responses = [$this->accountLogs([['account_log_id' => 'L-OK', 'trans_amount' => $second['pay_amount'], 'direction' => '收入', 'trans_dt' => $now]])];
        $this->assertSame(0, make(AlipayTransferService::class)->reconcile($account));

        // 「我已付款」立即核对；取消
        $this->http->responses = [$this->accountLogs([])];
        $this->assertSame('pending', $this->body($this->jsonRequest('POST', "/merchant/online-payments/{$second['id']}/check", $token))['status']);
        $this->assertSame('cancelled', $this->body($this->jsonRequest('POST', "/merchant/online-payments/{$second['id']}/cancel", $token))['status']);
        $this->assertNull($this->body($this->jsonRequest('GET', "/merchant/online-payments/{$second['id']}", $token))['qr_content']);
        $this->assertSame(422, $this->jsonRequest('POST', "/merchant/online-payments/{$p['id']}/cancel", $token)->getStatusCode(), '已到账不能取消');
        $this->assertNotNull(MerchantOnlinePayment::find($second['id'])->active_amount, '取消后核对期内不释放金额');

        $list = $this->body($this->jsonRequest('GET', '/merchant/online-payments?status=paid', $token));
        $this->assertSame([$p['id']], array_column($list['data'], 'id'));

        // 账户停用：不能再发起，已发起的单照常核对
        AlipayAccount::where('id', $account)->update(['status' => AlipayAccount::STATUS_DISABLED]);
        $this->assertFalse($this->body($this->jsonRequest('GET', '/merchant/online-payments/options', $token))['enabled']);
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/online-payments', $token, ['amount' => '100'])->getStatusCode());
        $third = MerchantOnlinePayment::where('merchant_id', $mine->id)->where('status', 'pending')->first();
        $this->http->responses = [$this->accountLogs([['account_log_id' => 'L-THIRD', 'trans_amount' => $third->pay_amount, 'direction' => '收入', 'trans_dt' => $now]])];
        $this->assertSame(1, make(AlipayTransferService::class)->reconcile($account));
    }

    public function testEachAccountIsReconciledSeparately()
    {
        $accountA = $this->createAccount();
        $accountB = $this->createAccount('2021000000000002');
        $a = $this->createMerchant();
        $b = $this->createMerchant();
        $this->bind($a, $accountA);
        $this->bind($b, $accountB);
        $pa = $this->body($this->jsonRequest('POST', '/merchant/online-payments', $this->merchantLogin($a), ['amount' => '10']));
        $pb = $this->body($this->jsonRequest('POST', '/merchant/online-payments', $this->merchantLogin($b), ['amount' => '10']));
        $this->assertSame([$accountA, $accountB], [MerchantOnlinePayment::find($pa['id'])->alipay_account_id, MerchantOnlinePayment::find($pb['id'])->alipay_account_id]);

        // 账户 A 的流水里有 B 的应付金额也不认给 B：各账户只认自己的单；A 查询失败不影响 B
        $now = date('Y-m-d H:i:s');
        $this->http->responses = [
            $this->alipayResponse(['code' => '40004', 'msg' => 'Business Failed', 'sub_msg' => 'ISV权限不足']),
            $this->accountLogs([['account_log_id' => 'B-1', 'trans_amount' => $pb['pay_amount'], 'direction' => '收入', 'trans_dt' => $now]]),
        ];
        make(AlipayTransferService::class)->reconcile();
        $this->assertSame(['pending', 'paid'], [MerchantOnlinePayment::find($pa['id'])->status, MerchantOnlinePayment::find($pb['id'])->status]);
        $appIds = array_map(static fn (array $r) => $r['payload']['app_id'], $this->http->requests);
        $this->assertContains(self::APP_ID, $appIds);
        $this->assertContains('2021000000000002', $appIds);
    }

    public function testExpireAndAdminConfirm()
    {
        $merchant = $this->createMerchant('0.00');
        $this->bind($merchant, $this->createAccount());
        $token = $this->merchantLogin($merchant);
        $viewer = $this->loginAs($this->createAdminWithPermissions(['funding.view']));
        $reviewer = $this->loginAs($this->createAdminWithPermissions(['funding.view', 'funding.review']));

        $p = $this->body($this->jsonRequest('POST', '/merchant/online-payments', $token, ['amount' => '50']));
        $other = $this->body($this->jsonRequest('POST', '/merchant/online-payments', $token, ['amount' => '20']));

        // 核对期过了：过期并释放金额
        MerchantOnlinePayment::whereIn('id', [$p['id'], $other['id']])->update(['expires_at' => date('Y-m-d H:i:s', time() - 1200), 'checking_until' => date('Y-m-d H:i:s', time() - 600)]);
        $this->assertGreaterThanOrEqual(2, make(AlipayTransferService::class)->expire());
        $expired = MerchantOnlinePayment::find($p['id']);
        $this->assertSame([MerchantOnlinePayment::STATUS_EXPIRED, null], [$expired->status, $expired->active_amount]);

        $list = $this->body($this->jsonRequest('GET', "/admin/funding/online-payments?merchant_id={$merchant->id}&status=expired", $viewer));
        $this->assertSame([$other['id'], $p['id']], array_column($list['data'], 'id'));
        $this->assertSame([], $this->body($this->jsonRequest('GET', '/admin/funding/online-payments?keyword=AT00000000000000000000', $viewer))['data']);
        $this->assertSame(403, $this->jsonRequest('POST', "/admin/funding/online-payments/{$p['id']}/candidates", $viewer)->getStatusCode());

        // 查找到账记录：金额相等、下单之后、没入过账的收入；备注是这单单号的排前面
        $later = date('Y-m-d H:i:s', time() + 5);
        $logs = $this->accountLogs([
            ['account_log_id' => 'C-1', 'trans_amount' => $p['pay_amount'], 'direction' => '收入', 'trans_dt' => date('Y-m-d H:i:s')],
            ['account_log_id' => 'C-2', 'trans_amount' => $p['pay_amount'], 'direction' => '收入', 'trans_dt' => $later, 'trans_memo' => $p['order_no']],
            ['account_log_id' => 'C-OLD', 'trans_amount' => $p['pay_amount'], 'direction' => '收入', 'trans_dt' => date('Y-m-d H:i:s', time() - 7200)],
            ['account_log_id' => 'C-AMOUNT', 'trans_amount' => '50.00', 'direction' => '收入', 'trans_dt' => $later],
        ]);
        $this->http->responses = [$logs];
        $candidates = $this->body($this->jsonRequest('POST', "/admin/funding/online-payments/{$p['id']}/candidates", $reviewer));
        $this->assertSame(['C-2', 'C-1'], array_column($candidates, 'account_log_id'));
        $this->assertTrue($candidates[0]['memo_matches']);

        $this->http->responses = [$logs];
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/funding/online-payments/{$p['id']}/confirm", $reviewer, ['account_log_id' => 'C-OLD'])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/funding/online-payments/{$p['id']}/confirm", $reviewer, [])->getStatusCode(), '不选流水必须写备注');
        $this->assertSame(403, $this->jsonRequest('POST', "/admin/funding/online-payments/{$p['id']}/confirm", $viewer, ['remark' => 'x'])->getStatusCode());

        $this->http->responses = [$logs];
        $confirmed = $this->body($this->jsonRequest('POST', "/admin/funding/online-payments/{$p['id']}/confirm", $reviewer, ['account_log_id' => 'C-2', 'remark' => '过期后到账']));
        $this->assertSame(['paid', 'manual', 'C-2', $p['order_no']], [$confirmed['status'], $confirmed['confirm_type'], $confirmed['account_log_id'], $confirmed['trans_memo']]);
        $this->assertNotNull($confirmed['confirmer_name']);
        $this->assertSame($p['pay_amount'], Merchant::find($merchant->id)->balance);
        $this->assertSame("支付宝转账 {$p['order_no']} 后台确认入账", MerchantBalanceLog::find($confirmed['balance_log_id'])->remark);
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/funding/online-payments/{$p['id']}/confirm", $reviewer, ['remark' => '再来一次'])->getStatusCode());

        // 不带流水直接确认（写了备注）
        $direct = $this->body($this->jsonRequest('POST', "/admin/funding/online-payments/{$other['id']}/confirm", $reviewer, ['remark' => '支付宝 App 里核实到账']));
        $this->assertSame(['paid', null, '支付宝 App 里核实到账'], [$direct['status'], $direct['account_log_id'], $direct['remark']]);
        $this->assertSame(bcadd($p['pay_amount'], $other['pay_amount'], 2), Merchant::find($merchant->id)->balance);
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
            'expire_minutes' => 15,
            'min_amount' => '1',
            'max_amount' => '5000',
            'remark' => '',
        ];
    }

    private function createAccount(string $appId = self::APP_ID): int
    {
        $admin = $this->loginAs($this->createAdminWithPermissions(['setting.manage']));
        $account = $this->body($this->jsonRequest('POST', '/admin/alipay-accounts', $admin, $this->accountData($appId)));
        $this->accountIds[] = $account['id'];

        return $account['id'];
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
