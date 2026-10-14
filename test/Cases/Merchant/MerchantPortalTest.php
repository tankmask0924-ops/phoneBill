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
use App\Model\ProductDailyStat;
use App\Security\Encrypter;
use HyperfTest\Cases\Admin\CreatesAdmins;
use HyperfTest\Cases\Admin\CreatesCatalog;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

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
        $this->assertSame($myOrder->mobile, $logs['data'][1]['mobile']);

        // 按订单号筛：平台订单号、商户订单号都行，别人的订单号查不到
        foreach ([$myOrder->order_no, $myOrder->merchant_order_no] as $no) {
            $filtered = $this->body($this->jsonRequest('GET', '/merchant/balance-logs?order_no=' . $no, $token));
            $this->assertSame([$myOrder->id], array_column($filtered['data'], 'order_id'), $no);
        }
        $this->assertSame([], $this->body($this->jsonRequest('GET', '/merchant/balance-logs?order_no=' . $theirOrder->order_no, $token))['data']);

        // 平台后台的资金流水同样带手机号，可按平台订单号筛
        $adminToken = $this->loginAs($this->createAdminWithPermissions(['merchant.view']));
        $adminLogs = $this->body($this->jsonRequest('GET', '/admin/balance-logs?order_no=' . $myOrder->order_no, $adminToken));
        $this->assertSame([$myOrder->id], array_column($adminLogs['data'], 'order_id'));
        $this->assertSame($myOrder->mobile, $adminLogs['data'][0]['mobile']);

        $dashboard = $this->body($this->jsonRequest('GET', '/merchant/dashboard', $token));
        $this->assertSame(Merchant::find($mine->id)->balance, $dashboard['balance']);
        $this->assertSame(2, $dashboard['today']['order_count']);
        $this->assertSame(1, $dashboard['today']['success_count']);
        $this->assertSame('99.50', $dashboard['today']['success_amount']);

        $account = $this->body($this->jsonRequest('GET', '/merchant/account', $token));
        $this->assertSame($mine->app_key, $account['app_key']);
        $this->assertArrayNotHasKey('app_secret', $account);
        $this->assertArrayNotHasKey('products', $account, '商品挪到「可下单商品」');
    }

    public function testOrdersFilterByOperatorProvinceAndMobiles()
    {
        [$merchant] = $this->merchantWithProduct();
        $token = $this->merchantLogin($this->createMerchantUser($merchant)->username, self::PASSWORD);
        $gd = $this->createOrder($merchant, Order::STATUS_SUCCESS);
        $hn = $this->createOrder($merchant, Order::STATUS_SUCCESS);
        $hn->update(['operator' => 'cucc', 'province' => '湖南']);
        $other = $this->createOrder($merchant, Order::STATUS_FAILED);
        $ids = fn (array $query) => array_column($this->body($this->jsonRequest('GET', '/merchant/orders?' . http_build_query($query), $token))['data'], 'id');

        $this->assertSame([$hn->id], $ids(['operator' => 'cucc']));
        $this->assertSame([$hn->id], $ids(['province' => '湖南']));
        $this->assertEqualsCanonicalizing([$gd->id, $other->id], $ids(['province' => '广东']));
        $this->assertEqualsCanonicalizing([$gd->id, $hn->id], $ids(['mobile' => "{$gd->mobile}\n{$hn->mobile}"]), '批量手机号');
        $this->assertCount(31, $this->body($this->jsonRequest('GET', '/merchant/order-filter-options', $token))['provinces']);
    }

    public function testOrderSummaryAndFaceValueCategoryFilters()
    {
        [$merchant, $product] = $this->merchantWithProduct();
        $category = $this->createCategory();
        $product->update(['category_id' => $category->id]);
        $token = $this->merchantLogin($this->createMerchantUser($merchant)->username, self::PASSWORD);
        $now = time();
        $success = $this->createOrder($merchant, Order::STATUS_SUCCESS);
        Order::where('id', $success->id)->update(['product_id' => $product->id, 'created_at' => date('Y-m-d H:i:s', $now - 30), 'finished_at' => date('Y-m-d H:i:s', $now)]);
        $small = $this->createOrder($merchant, Order::STATUS_SUCCESS);
        Order::where('id', $small->id)->update(['face_value' => 50, 'sale_price' => '49.80', 'created_at' => date('Y-m-d H:i:s', $now - 10), 'finished_at' => date('Y-m-d H:i:s', $now)]);
        $this->createOrder($merchant, Order::STATUS_FAILED);
        // 人工处理单手动回调之前，对商户算充值中，不算成功
        $manual = $this->createOrder($merchant, Order::STATUS_SUCCESS);
        Order::where('id', $manual->id)->update(['manual_only' => true, 'manual_notified_at' => null]);
        $get = fn (array $query) => $this->body($this->jsonRequest('GET', '/merchant/orders?' . http_build_query($query), $token));

        $all = $get([]);
        $this->assertSame(4, $all['total']);
        $this->assertSame([
            'order_count' => 4,
            'success_count' => 2,
            'failed_count' => 1,
            'processing_count' => 1,
            'success_rate' => '66.66',
            'amount' => '149.30',
            'avg_duration' => 20,
        ], $all['summary']);

        $this->assertSame([$small->id], array_column($get(['face_value' => 50])['data'], 'id'));
        $this->assertSame([$success->id], array_column($get(['category_id' => $category->id])['data'], 'id'));
        $empty = $get(['face_value' => 999]);
        $this->assertSame([], $empty['data']);
        $this->assertSame(0, $empty['summary']['order_count']);
        $this->assertNull($empty['summary']['success_rate']);
        $this->assertSame('0.00', $empty['summary']['amount']);

        $options = $this->body($this->jsonRequest('GET', '/merchant/order-filter-options', $token));
        $this->assertSame([100], $options['face_values'], '只取已开通商品的面值');
        $this->assertSame([['id' => $category->id, 'name' => $category->name]], $options['categories']);

        $products = $this->body($this->jsonRequest('GET', '/merchant/products', $token));
        $this->assertSame($category->id, $products['products'][0]['category_id']);
        $this->assertSame($category->name, $products['products'][0]['category_name']);
        $this->assertSame([['id' => $category->id, 'name' => $category->name]], $products['categories']);
    }

    public function testProductsWithRecentStats()
    {
        [$mine, $product] = $this->merchantWithProduct();
        [, $notMine] = $this->merchantWithProduct();
        $token = $this->merchantLogin($this->createMerchantUser($mine)->username, self::PASSWORD);
        $stat = static fn (string $date, int $orders, int $success, int $failed, int $duration) => ProductDailyStat::create([
            'stat_date' => $date, 'product_id' => $product->id, 'operator' => 'cmcc', 'order_count' => $orders, 'success_count' => $success,
            'failed_count' => $failed, 'unfinished_count' => 0, 'total_duration' => $duration, 'computed_at' => date('Y-m-d H:i:s'),
        ]);
        try {
            // 近 7 天（不含今天）的两天合起来算：成功 8+1，失败 2+0，耗时 (80+20)/9；8 天前的不算
            $stat(date('Y-m-d', strtotime('-1 day')), 10, 8, 2, 80);
            $stat(date('Y-m-d', strtotime('-7 days')), 1, 1, 0, 20);
            $stat(date('Y-m-d', strtotime('-8 days')), 100, 0, 100, 0);

            $body = $this->body($this->jsonRequest('GET', '/merchant/products', $token));
            $this->assertSame(date('Y-m-d', strtotime('-7 days')), $body['from']);
            $this->assertSame(date('Y-m-d', strtotime('-1 day')), $body['to']);
            $this->assertSame([$product->code], array_column($body['products'], 'product_code'), '只看到自己开通的');
            $this->assertNotContains($notMine->code, array_column($body['products'], 'product_code'));
            $this->assertSame(
                [['operator' => 'cmcc', 'price' => '99.50', 'order_count' => 11, 'success_rate' => '81.81', 'avg_duration' => 11]],
                $body['products'][0]['operators']
            );
        } finally {
            ProductDailyStat::where('product_id', $product->id)->delete();
        }
    }

    public function testMerchantManagesIpWhitelistAndSecret()
    {
        $merchant = $this->createMerchant();
        $token = $this->merchantLogin($this->createMerchantUser($merchant)->username, self::PASSWORD);

        // 要再输一次登录密码；IP 不合法不保存
        $this->assertSame(422, $this->jsonRequest('PUT', '/merchant/account/ip-whitelist', $token, ['password' => 'wrong', 'ip_whitelist' => '1.2.3.4'])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('PUT', '/merchant/account/ip-whitelist', $token, ['password' => self::PASSWORD, 'ip_whitelist' => '1.2.3.999'])->getStatusCode());
        $this->assertNull(Merchant::find($merchant->id)->ip_whitelist);

        $saved = $this->body($this->jsonRequest('PUT', '/merchant/account/ip-whitelist', $token, ['password' => self::PASSWORD, 'ip_whitelist' => "1.2.3.4\n10.0.0.0/8"]));
        $this->assertSame(['1.2.3.4', '10.0.0.0/8'], $saved['ip_whitelist']);
        $this->assertSame(['1.2.3.4', '10.0.0.0/8'], $this->body($this->jsonRequest('GET', '/merchant/account', $token))['ip_whitelist']);
        $this->assertSame([], $this->body($this->jsonRequest('PUT', '/merchant/account/ip-whitelist', $token, ['password' => self::PASSWORD, 'ip_whitelist' => '']))['ip_whitelist'], '留空表示不限制');

        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/account/reset-secret', $token, ['password' => 'wrong'])->getStatusCode());
        $this->assertSame(self::MERCHANT_SECRET, make(Encrypter::class)->decrypt(Merchant::find($merchant->id)->app_secret), '密码不对不重置');
        $reset = $this->body($this->jsonRequest('POST', '/merchant/account/reset-secret', $token, ['password' => self::PASSWORD]));
        $this->assertSame($merchant->app_key, $reset['app_key']);
        $this->assertSame(64, strlen($reset['app_secret']));
        $this->assertSame($reset['app_secret'], make(Encrypter::class)->decrypt(Merchant::find($merchant->id)->app_secret));
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
