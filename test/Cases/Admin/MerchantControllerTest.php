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

use App\Model\AdminOperationLog;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Security\Encrypter;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 商户：资料、密钥、IP 白名单、加减款与资金流水、商户商品与价格。
 *
 * @internal
 * @coversNothing
 */
class MerchantControllerTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

    protected function tearDown(): void
    {
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testCreateShowsSecretOnceAndResetReplacesIt()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['merchant.view', 'merchant.manage']));
        $name = $this->uniq('商户');

        $created = $this->jsonRequest('POST', '/admin/merchants', $token, [
            'name' => $name,
            'contact' => '张三',
            'notify_url' => 'https://example.test/notify',
            'ip_whitelist' => "1.2.3.4\n10.0.0.0/8, 1.2.3.4",
        ]);
        $body = $this->body($created);
        $this->assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        $this->merchantIds[] = $body['id'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $body['app_key']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['app_secret']);
        $this->assertSame(['1.2.3.4', '10.0.0.0/8'], $body['ip_whitelist']);
        $this->assertSame('0.00', $body['balance']);
        $stored = Merchant::find($body['id']);
        $this->assertNotSame($body['app_secret'], $stored->app_secret);
        $this->assertSame($body['app_secret'], make(Encrypter::class)->decrypt($stored->app_secret));

        $list = $this->body($this->jsonRequest('GET', '/admin/merchants?keyword=' . urlencode($name), $token));
        $this->assertSame(1, $list['total']);
        $this->assertArrayNotHasKey('app_secret', $list['data'][0]);

        $reset = $this->body($this->jsonRequest('POST', "/admin/merchants/{$body['id']}/secret", $token));
        $this->assertNotSame($body['app_secret'], $reset['app_secret']);
        $this->assertSame($reset['app_secret'], make(Encrypter::class)->decrypt(Merchant::find($body['id'])->app_secret));
    }

    public function testProfileValidation()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['merchant.manage']));
        $existing = $this->createMerchant();

        foreach ([
            ['name' => ''],
            ['name' => $existing->name],
            ['notify_url' => 'ftp://example.test'],
            ['notify_url' => 'not a url'],
            ['ip_whitelist' => '1.2.3.999'],
            ['ip_whitelist' => '10.0.0.0/33'],
            ['rate_limit_seconds' => -1],
            ['rate_limit_orders' => 'x'],
        ] as $override) {
            $response = $this->jsonRequest('POST', '/admin/merchants', $token, array_merge(['name' => $this->uniq('商户')], $override));
            $this->assertSame(422, $response->getStatusCode(), json_encode($override, JSON_UNESCAPED_UNICODE));
        }

        $updated = $this->body($this->jsonRequest('PUT', "/admin/merchants/{$existing->id}", $token, ['ip_whitelist' => '', 'notify_url' => '']));
        $this->assertSame([], $updated['ip_whitelist']);
        $this->assertNull($updated['notify_url']);
        $this->assertSame([0, 0], [$updated['rate_limit_seconds'], $updated['rate_limit_orders']]);
        $limited = $this->body($this->jsonRequest('PUT', "/admin/merchants/{$existing->id}", $token, ['rate_limit_seconds' => 5, 'rate_limit_orders' => 1]));
        $this->assertSame([5, 1], [$limited['rate_limit_seconds'], $limited['rate_limit_orders']]);
        $this->assertSame('disabled', $this->body($this->jsonRequest('POST', "/admin/merchants/{$existing->id}/status", $token, ['status' => 'disabled']))['status']);
    }

    public function testAdjustBalanceWritesLogs()
    {
        $admin = $this->createAdminWithPermissions(['merchant.view', 'merchant.balance']);
        $token = $this->loginAs($admin);
        $merchant = $this->createMerchant();

        $this->assertSame('100.50', $this->body($this->jsonRequest('POST', "/admin/merchants/{$merchant->id}/balance", $token, [
            'type' => 'recharge', 'amount' => '100.5', 'remark' => '对公转账',
        ]))['balance']);
        $this->assertSame('70.50', $this->body($this->jsonRequest('POST', "/admin/merchants/{$merchant->id}/balance", $token, [
            'type' => 'deduct', 'amount' => 30, 'remark' => '多充退回',
        ]))['balance']);

        foreach ([
            ['type' => 'deduct', 'amount' => '70.51', 'remark' => 'x'],
            ['type' => 'recharge', 'amount' => '0', 'remark' => 'x'],
            ['type' => 'recharge', 'amount' => '10', 'remark' => ''],
            ['type' => 'gift', 'amount' => '10', 'remark' => 'x'],
        ] as $data) {
            $this->assertSame(422, $this->jsonRequest('POST', "/admin/merchants/{$merchant->id}/balance", $token, $data)->getStatusCode(), json_encode($data));
        }
        $this->assertSame('70.50', Merchant::find($merchant->id)->balance);

        $logs = $this->body($this->jsonRequest('GET', "/admin/balance-logs?merchant_id={$merchant->id}", $token));
        $this->assertSame(2, $logs['total']);
        $this->assertSame(['-30.00', '100.50'], array_column($logs['data'], 'amount'), '新的在前');
        $this->assertSame(['70.50', '100.50'], array_column($logs['data'], 'balance_after'));
        $this->assertSame($admin->real_name, $logs['data'][0]['admin_name']);
        $this->assertSame(0, $this->body($this->jsonRequest('GET', "/admin/balance-logs?merchant_id={$merchant->id}&type=order_pay", $token))['total']);

        $csv = (string) $this->jsonRequest('GET', "/admin/balance-logs/export?merchant_id={$merchant->id}", $token)->getBody();
        $lines = explode("\n", trim($csv));
        $this->assertStringStartsWith("\xEF\xBB\xBF时间,商户,类型,金额,变动后余额", $lines[0]);
        $this->assertCount(3, $lines, '表头 + 2 条流水');
        $this->assertStringContainsString(",{$merchant->name},扣款,-30.00,70.50,", $lines[1]);
        $this->assertStringContainsString($admin->real_name, $lines[1]);
        $this->assertCount(1, explode("\n", trim((string) $this->jsonRequest('GET', "/admin/balance-logs/export?merchant_id={$merchant->id}&type=order_pay", $token)->getBody())), '筛不出数据时只有表头');
        $this->assertSame(2, MerchantBalanceLog::where('merchant_id', $merchant->id)->count());
    }

    public function testCreditLimitAllowsDeductingBelowZero()
    {
        $admin = $this->createAdminWithPermissions(['merchant.view', 'merchant.balance', 'merchant.credit', 'operation_log.view']);
        $token = $this->loginAs($admin);
        $merchant = $this->createMerchant();

        foreach (['-1', 'abc', '1.234', '10000000.01', null] as $bad) {
            $this->assertSame(422, $this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/credit-limit", $token, ['credit_limit' => $bad])->getStatusCode(), var_export($bad, true));
        }
        $set = $this->body($this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/credit-limit", $token, ['credit_limit' => '100']));
        $this->assertSame(['0.00', '100.00', '100.00'], [$set['balance'], $set['credit_limit'], $set['available_balance']]);

        // 扣到负数，但不能超出授信
        $deducted = $this->body($this->jsonRequest('POST', "/admin/merchants/{$merchant->id}/balance", $token, ['type' => 'deduct', 'amount' => '80', 'remark' => '先用']));
        $this->assertSame(['-80.00', '20.00'], [$deducted['balance'], $deducted['available_balance']]);
        $response = $this->jsonRequest('POST', "/admin/merchants/{$merchant->id}/balance", $token, ['type' => 'deduct', 'amount' => '20.01', 'remark' => 'x']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('超出授信额度', (string) $response->getBody());

        // 调低到比欠款还少：允许，已有欠款不动；之后加款照常加（还欠款）
        $lowered = $this->body($this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/credit-limit", $token, ['credit_limit' => '10']));
        $this->assertSame(['-80.00', '10.00', '-70.00'], [$lowered['balance'], $lowered['credit_limit'], $lowered['available_balance']]);
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/merchants/{$merchant->id}/balance", $token, ['type' => 'deduct', 'amount' => '1', 'remark' => 'x'])->getStatusCode());
        $this->assertSame('-30.00', $this->body($this->jsonRequest('POST', "/admin/merchants/{$merchant->id}/balance", $token, ['type' => 'recharge', 'amount' => '50', 'remark' => '还款']))['balance']);
        $this->assertSame(2, AdminOperationLog::where('admin_user_id', $admin->id)->where('action', 'set_merchant_credit_limit')->where('target_id', $merchant->id)->count(), '改了两次授信');

        // 授信要单独的权限
        $balanceOnly = $this->loginAs($this->createAdminWithPermissions(['merchant.view', 'merchant.balance', 'merchant.manage']));
        $this->assertSame(403, $this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/credit-limit", $balanceOnly, ['credit_limit' => '999'])->getStatusCode());
        $this->assertSame('10.00', Merchant::find($merchant->id)->credit_limit);
    }

    public function testBalanceNeedsItsOwnPermission()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['merchant.view', 'merchant.manage']));
        $merchant = $this->createMerchant();

        $response = $this->jsonRequest('POST', "/admin/merchants/{$merchant->id}/balance", $token, ['type' => 'recharge', 'amount' => '10', 'remark' => 'x']);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testOpenProductsWithPerOperatorPrices()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['merchant.view', 'merchant.price']));
        $merchant = $this->createMerchant();
        $supplier = $this->createSupplier();
        $mobile = $this->createSupplierProduct($supplier, ['cmcc'], 100, '98.80');
        $telecom = $this->createSupplierProduct($supplier, ['ctcc'], 100, '98.90');
        $product = $this->createProduct([$mobile->id => 1, $telecom->id => 1]);

        $this->assertContains($product->id, array_column($this->body($this->jsonRequest('GET', "/admin/merchants/{$merchant->id}/available-products", $token)), 'id'));

        $saved = $this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/products/{$product->id}", $token, [
            'prices' => ['cmcc' => '99.5', 'ctcc' => '98.5'],
        ]);
        $body = $this->body($saved);
        $this->assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $this->assertSame(['cmcc' => '99.50', 'ctcc' => '98.50'], $body['prices']);
        $this->assertSame('active', $body['status']);
        $this->assertCount(1, $body['warnings'], '电信价格低于成本');

        $this->assertNotContains($product->id, array_column($this->body($this->jsonRequest('GET', "/admin/merchants/{$merchant->id}/available-products", $token)), 'id'));

        $updated = $this->body($this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/products/{$product->id}", $token, [
            'status' => 'disabled',
            'prices' => ['cmcc' => '99.60'],
        ]));
        $this->assertSame('disabled', $updated['status']);
        $this->assertSame(['cmcc' => '99.60'], $updated['prices']);
        $this->assertStringContainsString('电信', $updated['warnings'][0]);

        $list = $this->body($this->jsonRequest('GET', "/admin/merchants/{$merchant->id}/products", $token));
        $this->assertSame([$product->id], array_column($list, 'product_id'));

        $this->assertSame(422, $this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/products/{$product->id}", $token, [
            'prices' => ['cucc' => '99'],
        ])->getStatusCode(), '商品不支持联通');
        $this->assertSame(404, $this->jsonRequest('PUT', "/admin/merchants/{$merchant->id}/products/999999999", $token, ['prices' => []])->getStatusCode());
    }

    public function testListWithNoMatches()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['merchant.view']));
        $response = $this->jsonRequest('GET', '/admin/merchants?keyword=' . $this->uniq('none_'), $token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->body($response)['data']);
        $this->assertSame([], $this->body($this->jsonRequest('GET', '/admin/balance-logs?merchant_id=999999999', $token))['data']);
    }
}
