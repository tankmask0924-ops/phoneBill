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
use App\Model\MerchantBalanceLog;
use App\Model\MerchantUser;
use App\Model\Order;
use App\Model\Product;
use HyperfTest\Cases\Admin\CreatesAdmins;
use HyperfTest\Cases\Admin\CreatesCatalog;
use HyperfTest\HttpTestCase;

/**
 * 商户后台：平台开账号、商户登录、只能看到自己的数据、不暴露成本和供应商、登录态和管理端互不通用。
 *
 * @internal
 * @coversNothing
 */
class MerchantPortalTest extends HttpTestCase
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

    public function testAdminOpensAccountAndMerchantLogsIn()
    {
        $admin = $this->loginAs($this->createAdminWithPermissions(['merchant.view', 'merchant.manage']));
        $merchant = $this->createMerchant('88.00');
        $username = $this->uniq('mu_');

        $created = $this->jsonRequest('POST', "/admin/merchants/{$merchant->id}/users", $admin, ['username' => $username, 'password' => 'init-pass', 'real_name' => '财务小王']);
        $this->assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/merchants/{$merchant->id}/users", $admin, ['username' => $username, 'password' => 'x'])->getStatusCode(), '账号重复');
        $this->assertSame([$username], array_column($this->body($this->jsonRequest('GET', "/admin/merchants/{$merchant->id}/users", $admin)), 'username'));

        $token = $this->merchantLogin($username, 'init-pass');
        $this->assertNotNull($token);
        $me = $this->body($this->jsonRequest('GET', '/merchant/auth/me', $token));
        $this->assertSame($merchant->name, $me['merchant_name']);

        // 改密码：旧 token 失效，新 token 可用
        $changed = $this->body($this->jsonRequest('PUT', '/merchant/auth/password', $token, ['old_password' => 'init-pass', 'new_password' => 'new-pass']));
        $this->assertSame(401, $this->jsonRequest('GET', '/merchant/auth/me', $token)->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('GET', '/merchant/auth/me', $changed['token'])->getStatusCode());

        // 平台禁用账号：立即失效，也不能再登录
        $userId = $this->body($created)['id'];
        $this->jsonRequest('POST', "/admin/merchants/{$merchant->id}/users/{$userId}/status", $admin, ['status' => 'disabled']);
        $this->assertSame(401, $this->jsonRequest('GET', '/merchant/auth/me', $changed['token'])->getStatusCode());
        $this->assertSame(403, $this->loginResponse($username, 'new-pass')->getStatusCode());

        // 平台重置密码
        $this->jsonRequest('POST', "/admin/merchants/{$merchant->id}/users/{$userId}/status", $admin, ['status' => 'active']);
        $this->jsonRequest('POST', "/admin/merchants/{$merchant->id}/users/{$userId}/password", $admin, ['password' => 'reset-pass']);
        $this->assertNotNull($this->merchantLogin($username, 'reset-pass'));
        $other = $this->createMerchant();
        $this->assertSame(404, $this->jsonRequest('POST', "/admin/merchants/{$other->id}/users/{$userId}/password", $admin, ['password' => 'x'])->getStatusCode(), '账号不属于这个商户');
    }

    public function testTokensAreNotInterchangeable()
    {
        $adminToken = $this->loginAs($this->createAdminWithPermissions(['merchant.view']));
        $merchantToken = $this->merchantLogin($this->createMerchantUser($this->createMerchant())->username, self::PASSWORD);

        $this->assertSame(401, $this->jsonRequest('GET', '/merchant/dashboard', $adminToken)->getStatusCode());
        $this->assertSame(401, $this->jsonRequest('GET', '/admin/merchants', $merchantToken)->getStatusCode());
        $this->assertSame(401, $this->jsonRequest('GET', '/merchant/orders', null)->getStatusCode());
    }

    public function testMerchantOnlySeesOwnDataWithoutInternalFields()
    {
        [$mine, $product] = $this->merchantWithProduct();
        [$theirs] = $this->merchantWithProduct();
        $myOrder = $this->createOrder($mine, Order::STATUS_SUCCESS);
        $this->createOrder($mine, Order::STATUS_ABNORMAL);
        $theirOrder = $this->createOrder($theirs, Order::STATUS_SUCCESS);
        $token = $this->merchantLogin($this->createMerchantUser($mine)->username, self::PASSWORD);

        $orders = $this->body($this->jsonRequest('GET', '/merchant/orders', $token));
        $this->assertSame(2, $orders['total']);
        $this->assertNotContains($theirOrder->id, array_column($orders['data'], 'id'));
        $this->assertSame(['processing', 'success'], array_column($orders['data'], 'status'), '异常订单对商户显示为充值中');
        foreach (['cost_price', 'supplier_id', 'supplier_name', 'merchant_id'] as $internal) {
            $this->assertArrayNotHasKey($internal, $orders['data'][0]);
        }
        $this->assertSame(1, $this->body($this->jsonRequest('GET', '/merchant/orders?status=processing', $token))['total']);
        $this->assertSame([], $this->body($this->jsonRequest('GET', '/merchant/orders?keyword=no_such', $token))['data']);

        $detail = $this->body($this->jsonRequest('GET', "/merchant/orders/{$myOrder->id}", $token));
        $this->assertSame($myOrder->order_no, $detail['order_no']);
        $this->assertSame(['order_pay'], array_column($detail['balance_logs'], 'type'));
        $this->assertSame(404, $this->jsonRequest('GET', "/merchant/orders/{$theirOrder->id}", $token)->getStatusCode(), '看不到别人的订单');

        $logs = $this->body($this->jsonRequest('GET', '/merchant/balance-logs', $token));
        $this->assertSame(2, $logs['total']);
        $this->assertArrayNotHasKey('admin_user_id', $logs['data'][0]);
        $this->assertSame($myOrder->merchant_order_no, $logs['data'][1]['merchant_order_no']);

        $dashboard = $this->body($this->jsonRequest('GET', '/merchant/dashboard', $token));
        $this->assertSame(Merchant::find($mine->id)->balance, $dashboard['balance']);
        $this->assertSame(2, $dashboard['today']['order_count']);
        $this->assertSame(1, $dashboard['today']['success_count']);
        $this->assertSame('99.50', $dashboard['today']['success_amount']);

        $account = $this->body($this->jsonRequest('GET', '/merchant/account', $token));
        $this->assertSame($mine->app_key, $account['app_key']);
        $this->assertArrayNotHasKey('app_secret', $account);
        $this->assertSame([$product->code], array_column($account['products'], 'product_code'));
    }

    public function testLoginIsThrottledAfterRepeatedFailures()
    {
        $user = $this->createMerchantUser($this->createMerchant());
        for ($i = 0; $i < 10; ++$i) {
            $this->assertSame(401, $this->loginResponse($user->username, 'wrong')->getStatusCode());
        }
        $this->assertSame(429, $this->loginResponse($user->username, self::PASSWORD)->getStatusCode(), '密码对了也先锁着');
    }

    private function createMerchantUser(Merchant $merchant): MerchantUser
    {
        return MerchantUser::create([
            'merchant_id' => $merchant->id,
            'username' => $this->uniq('mu_'),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => 'active',
        ]);
    }

    /**
     * @return array{0: Merchant, 1: Product}
     */
    private function merchantWithProduct(): array
    {
        $sp = $this->createSupplierProduct($this->createSupplier(), ['cmcc'], 100, '98.00');
        $product = $this->createProduct([$sp->id => 1]);
        $merchant = $this->createMerchant('500.00');
        $this->openProduct($merchant, $product, ['cmcc' => '99.50']);

        return [$merchant, $product];
    }

    private function createOrder(Merchant $merchant, string $status): Order
    {
        $order = Order::create([
            'order_no' => date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => $this->uniq('M'),
            'product_id' => 0,
            'product_code' => 'P',
            'product_name' => '话费100',
            'mobile' => '1009' . random_int(1000000, 9999999),
            'operator' => 'cmcc',
            'province' => '广东',
            'face_value' => 100,
            'sale_price' => '99.50',
            'cost_price' => '98.00',
            'status' => $status,
            'notify_status' => 'none',
            'finished_at' => $status === Order::STATUS_SUCCESS ? date('Y-m-d H:i:s') : null,
        ]);
        MerchantBalanceLog::create([
            'merchant_id' => $merchant->id, 'type' => 'order_pay', 'amount' => '-99.50', 'balance_after' => '400.50',
            'order_id' => $order->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $order;
    }

    private function loginResponse(string $username, string $password)
    {
        return $this->client->request('POST', '/merchant/auth/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['username' => $username, 'password' => $password],
        ]);
    }

    private function merchantLogin(string $username, string $password): ?string
    {
        return $this->body($this->loginResponse($username, $password))['token'] ?? null;
    }
}
