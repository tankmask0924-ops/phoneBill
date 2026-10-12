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

use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantFundingRequest;
use App\Model\MerchantPayerAccount;
use App\Model\MerchantReceivingAccount;
use App\Model\MerchantUser;
use App\Model\ReceivingAccount;
use App\Model\SystemSetting;
use App\Network\HttpClient;
use App\Service\Setting\SettingService;
use App\Service\Storage\ObjectStorageService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\Cases\Admin\CreatesAdmins;
use HyperfTest\Cases\Admin\CreatesCatalog;
use HyperfTest\HttpTestCase;
use HyperfTest\Support\FakeHttpClient;

use function Hyperf\Support\make;

/**
 * 加款：存储配置、收款账户和分配、付款账号审核、商户提交申请（上传凭证）、审核入账 / 驳回。
 *
 * @internal
 * @coversNothing
 */
class FundingFlowTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

    /** 1x1 的 PNG */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private FakeHttpClient $http;

    /** 测试前的存储配置（测试库和开发库共用，测完还原） */
    private ?string $originalStorage = null;

    private bool $hadStorage = false;

    private array $receivingIds = [];

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->http = new FakeHttpClient();
        $this->http->responses = [['status' => 200, 'body' => '']];
        ApplicationContext::getContainer()->set(HttpClient::class, $this->http);
        $this->client = make(Client::class);
        $row = SystemSetting::where('key', ObjectStorageService::SETTING_KEY)->first();
        $this->hadStorage = $row !== null;
        $this->originalStorage = $row?->value;
    }

    protected function tearDown(): void
    {
        MerchantFundingRequest::whereIn('merchant_id', $this->merchantIds)->delete();
        MerchantPayerAccount::whereIn('merchant_id', $this->merchantIds)->delete();
        MerchantReceivingAccount::whereIn('receiving_account_id', $this->receivingIds)->delete();
        ReceivingAccount::destroy($this->receivingIds);
        if ($this->hadStorage) {
            SystemSetting::where('key', ObjectStorageService::SETTING_KEY)->update(['value' => $this->originalStorage]);
        } else {
            SystemSetting::where('key', ObjectStorageService::SETTING_KEY)->delete();
        }
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testStorageSetting()
    {
        $admin = $this->loginAs($this->createAdminWithPermissions(['setting.manage']));
        $valid = ['secret_id' => 'AKID_TEST', 'secret_key' => 'SK_TEST', 'region' => 'ap-guangzhou', 'bucket' => 'pb-1250000000', 'path_prefix' => '/test/'];

        $this->assertSame(422, $this->jsonRequest('PUT', '/admin/settings/storage', $admin, ['bucket' => 'no-appid'] + $valid)->getStatusCode());
        $saved = $this->body($this->jsonRequest('PUT', '/admin/settings/storage', $admin, $valid));
        $this->assertSame(['secret_id' => 'AKID_TEST', 'secret_key_set' => true, 'region' => 'ap-guangzhou', 'bucket' => 'pb-1250000000', 'path_prefix' => 'test'], $saved);
        $this->assertArrayNotHasKey('secret_key', $this->body($this->jsonRequest('GET', '/admin/settings/storage', $admin)));

        // SecretKey 留空不改
        $this->jsonRequest('PUT', '/admin/settings/storage', $admin, ['secret_key' => ''] + $valid);
        $this->assertSame('SK_TEST', make(ObjectStorageService::class)->config()['secret_key']);

        // 测试连接：上传再删除一个测试文件
        $this->http->responses = [['status' => 200, 'body' => ''], ['status' => 204, 'body' => '']];
        $this->assertTrue($this->body($this->jsonRequest('POST', '/admin/settings/storage/test', $admin))['success']);
        $this->assertSame(['PUT', 'DELETE'], array_column($this->http->requests, 'method'));
        $this->assertStringStartsWith('https://pb-1250000000.cos.ap-guangzhou.myqcloud.com/test/connection-test/', $this->http->requests[0]['url']);

        $this->http->responses = [['status' => 403, 'body' => '<Error><Code>AccessDenied</Code><Message>denied</Message></Error>']];
        $failed = $this->body($this->jsonRequest('POST', '/admin/settings/storage/test', $admin));
        $this->assertFalse($failed['success']);
        $this->assertStringContainsString('AccessDenied', $failed['message']);
    }

    public function testFundingFlow()
    {
        $this->configureStorage();
        $admin = $this->loginAs($this->createAdminWithPermissions(['funding.view', 'funding.review', 'funding.config']));
        $mine = $this->createMerchant('10.00');
        $theirs = $this->createMerchant();
        $token = $this->merchantLogin($mine);
        $theirToken = $this->merchantLogin($theirs);

        // 收款账户：对公账号必须是数字、要开户行
        $this->assertSame(422, $this->jsonRequest('POST', '/admin/receiving-accounts', $admin, ['type' => 'bank', 'account_name' => '某公司', 'account_no' => 'abc', 'bank_name' => '招行'])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('POST', '/admin/receiving-accounts', $admin, ['type' => 'wechat', 'account_name' => 'x', 'account_no' => '1'])->getStatusCode());
        $alipay = $this->body($this->jsonRequest('POST', '/admin/receiving-accounts', $admin, ['type' => 'alipay', 'account_name' => '平台公司', 'account_no' => 'pay@example.com', 'bank_name' => '忽略']));
        $bank = $this->body($this->jsonRequest('POST', '/admin/receiving-accounts', $admin, ['type' => 'bank', 'account_name' => '平台公司', 'account_no' => '6222 0000 1111', 'bank_name' => '招商银行深圳分行']));
        $this->receivingIds = [$alipay['id'], $bank['id']];
        $this->assertNull($alipay['bank_name']);
        $this->assertSame('622200001111', $bank['account_no']);

        // 只把支付宝分配给我
        $assigned = $this->body($this->jsonRequest('PUT', "/admin/receiving-accounts/{$alipay['id']}/merchants", $admin, ['merchant_ids' => [$mine->id]]));
        $this->assertSame([$mine->id], $assigned['merchant_ids']);
        $this->assertSame([$alipay['id']], array_column($this->body($this->jsonRequest('GET', '/merchant/receiving-accounts', $token)), 'id'));
        $this->assertSame([], $this->body($this->jsonRequest('GET', '/merchant/receiving-accounts', $theirToken)));

        // 付款账号：新增是待审核，没审核不能用
        $payer = $this->body($this->jsonRequest('POST', '/merchant/payer-accounts', $token, ['type' => 'bank', 'account_name' => '商户公司', 'account_no' => '6222111122223333', 'bank_name' => '工商银行']));
        $this->assertSame('pending', $payer['status']);
        $voucher = $this->uploadVoucher($token);
        $request = ['amount' => '500', 'payer_account_id' => $payer['id'], 'receiving_account_id' => $alipay['id'], 'paid_at' => date('Y-m-d H:i'), 'remark' => '第一笔', 'vouchers' => [$voucher['key']]];
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/funding-requests', $token, $request)->getStatusCode());

        // 审核通过后商户修改：回到待审核，再审一次
        $this->assertSame('approved', $this->body($this->jsonRequest('POST', "/admin/funding/payer-accounts/{$payer['id']}/approve", $admin))['status']);
        $edited = $this->body($this->jsonRequest('PUT', "/merchant/payer-accounts/{$payer['id']}", $token, ['type' => 'bank', 'account_name' => '商户公司', 'account_no' => '6222111122224444', 'bank_name' => '工商银行']));
        $this->assertSame('pending', $edited['status']);
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/funding-requests', $token, $request)->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/funding/payer-accounts/{$payer['id']}/reject", $admin, ['reason' => ''])->getStatusCode(), '驳回要写原因');
        $this->jsonRequest('POST', "/admin/funding/payer-accounts/{$payer['id']}/approve", $admin);
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/funding/payer-accounts/{$payer['id']}/approve", $admin)->getStatusCode(), '已经审过');

        // 别人看不到、改不了我的付款账号
        $this->assertSame([], $this->body($this->jsonRequest('GET', '/merchant/payer-accounts', $theirToken)));
        $this->assertSame(404, $this->jsonRequest('PUT', "/merchant/payer-accounts/{$payer['id']}", $theirToken, ['type' => 'alipay', 'account_name' => 'x', 'account_no' => 'x'])->getStatusCode());

        // 提交申请的校验：没分配的收款账户、别人的凭证、打款时间在未来、没有凭证
        $theirVoucher = $this->uploadVoucher($theirToken);
        foreach ([
            ['receiving_account_id' => $bank['id']],
            ['vouchers' => [$theirVoucher['key']]],
            ['vouchers' => []],
            ['paid_at' => date('Y-m-d H:i', time() + 3600)],
            ['amount' => '0'],
        ] as $override) {
            $this->assertSame(422, $this->jsonRequest('POST', '/merchant/funding-requests', $token, $override + $request)->getStatusCode(), json_encode($override));
        }

        $created = $this->body($this->jsonRequest('POST', '/merchant/funding-requests', $token, $request));
        $this->assertSame('pending', $created['status']);
        $this->assertSame('500.00', $created['amount']);
        $this->assertSame('6222111122224444', $created['payer']['account_no'], '用的是最新审核通过的内容');
        $this->assertSame('pay@example.com', $created['receiving']['account_no']);
        $this->assertCount(1, $created['voucher_urls']);

        // 平台看申请：带商户名、凭证链接；只读权限不能审
        $list = $this->body($this->jsonRequest('GET', "/admin/funding/requests?status=pending&merchant_id={$mine->id}", $admin));
        $this->assertSame([$created['id']], array_column($list['data'], 'id'));
        $detail = $this->body($this->jsonRequest('GET', "/admin/funding/requests/{$created['id']}", $admin));
        $this->assertSame($mine->name, $detail['merchant_name']);
        $this->assertStringStartsWith('https://pb-1250000000.cos.ap-guangzhou.myqcloud.com/', $detail['voucher_urls'][0]);
        $viewer = $this->loginAs($this->createAdminWithPermissions(['funding.view']));
        $this->assertSame(403, $this->jsonRequest('POST', "/admin/funding/requests/{$created['id']}/approve", $viewer)->getStatusCode());

        // 审核通过：按申请金额入账，写资金流水；不能重复入账
        $approved = $this->body($this->jsonRequest('POST', "/admin/funding/requests/{$created['id']}/approve", $admin, ['remark' => '已核对到账']));
        $this->assertSame('approved', $approved['status']);
        $this->assertSame('510.00', Merchant::find($mine->id)->balance);
        $log = MerchantBalanceLog::find($approved['balance_log_id']);
        $this->assertSame(MerchantBalanceLog::TYPE_RECHARGE, $log->type);
        $this->assertSame('500.00', $log->amount);
        $this->assertStringContainsString("#{$created['id']}", $log->remark);
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/funding/requests/{$created['id']}/approve", $admin)->getStatusCode());
        $this->assertSame('510.00', Merchant::find($mine->id)->balance);

        // 第二笔驳回：要写原因，不入账
        $second = $this->body($this->jsonRequest('POST', '/merchant/funding-requests', $token, ['amount' => '100'] + $request));
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/funding/requests/{$second['id']}/reject", $admin, ['remark' => ''])->getStatusCode());
        $rejected = $this->body($this->jsonRequest('POST', "/admin/funding/requests/{$second['id']}/reject", $admin, ['remark' => '没有收到款']));
        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('510.00', Merchant::find($mine->id)->balance);

        // 商户看自己的申请：看得到驳回原因，看不到审核人等内部信息；别人看不到
        $mineList = $this->body($this->jsonRequest('GET', '/merchant/funding-requests', $token));
        $this->assertSame(['rejected', 'approved'], array_column($mineList['data'], 'status'));
        $this->assertSame('没有收到款', $mineList['data'][0]['review_remark']);
        $this->assertArrayNotHasKey('reviewer_name', $mineList['data'][0]);
        $this->assertArrayNotHasKey('balance_log_id', $mineList['data'][0]);
        $this->assertSame(404, $this->jsonRequest('GET', "/merchant/funding-requests/{$created['id']}", $theirToken)->getStatusCode());
        $this->assertSame([], $this->body($this->jsonRequest('GET', '/merchant/funding-requests', $theirToken))['data']);
    }

    public function testUploadRejectsNonImagesAndNeedsStorage()
    {
        $token = $this->merchantLogin($this->createMerchant());
        SystemSetting::where('key', ObjectStorageService::SETTING_KEY)->delete();
        $this->assertSame(422, $this->upload($token, 'v.png', base64_decode(self::PNG))->getStatusCode(), '还没配置存储');

        $this->configureStorage();
        $this->assertSame(422, $this->upload($token, 'v.png', 'not an image')->getStatusCode());
        $this->assertSame(200, $this->upload($token, 'v.png', base64_decode(self::PNG))->getStatusCode());
    }

    private function configureStorage(): void
    {
        make(SettingService::class)->set(ObjectStorageService::SETTING_KEY, [
            'secret_id' => 'AKID_TEST', 'secret_key' => 'SK_TEST', 'region' => 'ap-guangzhou', 'bucket' => 'pb-1250000000', 'path_prefix' => 'test',
        ]);
    }

    /**
     * @return array{key: string, url: string}
     */
    private function uploadVoucher(?string $token): array
    {
        $response = $this->upload($token, 'voucher.png', base64_decode(self::PNG));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->body($response);
    }

    private function upload(?string $token, string $filename, string $contents)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $this->client->request('POST', '/merchant/funding-vouchers', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'multipart' => [['name' => 'file', 'contents' => $stream, 'filename' => $filename]],
        ]);
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
}
