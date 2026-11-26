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
use App\Model\MerchantUser;
use App\Model\Order;
use App\Service\Merchant\MerchantLookupService;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 商户、商户后台账号的软删除。
 *
 * @internal
 * @coversNothing
 */
class MerchantDeleteTest extends HttpTestCase
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

    public function testDeleteMerchantNeedsZeroBalanceAndFinishedOrders()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['merchant.view', 'merchant.manage']));
        $merchant = $this->createMerchant('10.00');
        $pending = $this->createOrder($merchant, Order::STATUS_PROCESSING);

        $this->assertSame(403, $this->jsonRequest('DELETE', "/admin/merchants/{$merchant->id}", $this->loginAs($this->createAdminWithPermissions(['merchant.view'])))->getStatusCode());

        $response = $this->jsonRequest('DELETE', "/admin/merchants/{$merchant->id}", $token);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('余额还有 10.00 元', (string) $response->getBody());

        $merchant->newQuery()->where('id', $merchant->id)->update(['balance' => '0.00']);
        $response = $this->jsonRequest('DELETE', "/admin/merchants/{$merchant->id}", $token);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('没出结果的订单', (string) $response->getBody());
        $this->assertNotNull(Merchant::find($merchant->id), '没删');

        $pending->update(['status' => Order::STATUS_SUCCESS]);
        $this->assertSame(200, $this->jsonRequest('DELETE', "/admin/merchants/{$merchant->id}", $token)->getStatusCode());
        $this->assertNull(Merchant::find($merchant->id));
        $this->assertNotNull(Merchant::withTrashed()->find($merchant->id)?->deleted_at, '软删除，数据还在');
        $this->assertSame(404, $this->jsonRequest('DELETE', "/admin/merchants/{$merchant->id}", $token)->getStatusCode());
        $this->assertTrue(AdminOperationLog::where('action', 'delete_merchant')->where('target_id', $merchant->id)->exists());
    }

    public function testDeletedMerchantIsGoneButHistoryKeepsItsName()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['merchant.view', 'merchant.manage', 'order.view', 'order.manage', 'order.reverse']));
        $merchant = $this->createMerchant();
        $user = $this->createMerchantUser($merchant);
        $order = $this->createOrder($merchant, Order::STATUS_SUCCESS);
        $merchantToken = $this->merchantLogin($user->username);
        $this->assertNotNull($merchantToken);
        $this->assertSame(200, $this->jsonRequest('GET', '/merchant/dashboard', $merchantToken)->getStatusCode());
        // 开放接口按 AppKey 查商户有缓存，先查一次让它缓存上
        $this->assertNotNull(make(MerchantLookupService::class)->findByAppKey($merchant->app_key));

        $this->assertSame(200, $this->jsonRequest('DELETE', "/admin/merchants/{$merchant->id}", $token)->getStatusCode());

        // 名下账号一起删掉：之前的登录失效，也不能再登录；开放接口找不到商户
        $this->assertNotNull(MerchantUser::withTrashed()->find($user->id)?->deleted_at);
        $this->assertSame(401, $this->jsonRequest('GET', '/merchant/dashboard', $merchantToken)->getStatusCode());
        $this->assertNull($this->merchantLogin($user->username));
        $this->assertNull(make(MerchantLookupService::class)->findByAppKey($merchant->app_key), '删除后清掉了缓存');

        // 商户列表、详情接口里没有了
        $list = $this->body($this->jsonRequest('GET', '/admin/merchants?keyword=' . urlencode($merchant->name), $token));
        $this->assertSame([], $list['data']);
        $this->assertNotContains($merchant->id, array_column($this->body($this->jsonRequest('GET', '/admin/merchants/options', $token)), 'id'));
        $this->assertSame(404, $this->jsonRequest('GET', "/admin/merchants/{$merchant->id}/users", $token)->getStatusCode());

        // 历史订单还能按商户查到，名字带「（已删除）」；但不能再冲正、手动回调
        $orders = $this->body($this->jsonRequest('GET', "/admin/orders?merchant_id={$merchant->id}", $token));
        $this->assertSame([$order->id], array_column($orders['data'], 'id'));
        $this->assertSame($merchant->name . '（已删除）', $orders['data'][0]['merchant_name']);
        $options = array_column($this->body($this->jsonRequest('GET', '/admin/orders/filter-options', $token))['merchants'], 'name', 'id');
        $this->assertSame($merchant->name . '（已删除）', $options[$merchant->id]);
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/orders/{$order->id}/reverse", $token, ['remark' => 'x'])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/orders/{$order->id}/notify", $token, ['result' => 'success', 'remark' => 'x'])->getStatusCode());
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);

        // 名称仍然占用
        $response = $this->jsonRequest('POST', '/admin/merchants', $token, ['name' => $merchant->name]);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('已删除的商户也占用名称', (string) $response->getBody());
    }

    public function testDeleteMerchantUser()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['merchant.view', 'merchant.manage']));
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $user = $this->createMerchantUser($merchant);
        $kept = $this->createMerchantUser($merchant);
        $merchantToken = $this->merchantLogin($user->username);
        $this->assertNotNull($merchantToken);

        $this->assertSame(404, $this->jsonRequest('DELETE', "/admin/merchants/{$other->id}/users/{$user->id}", $token)->getStatusCode(), '账号不是这个商户的');
        $this->assertSame(200, $this->jsonRequest('DELETE', "/admin/merchants/{$merchant->id}/users/{$user->id}", $token)->getStatusCode());

        $this->assertNotNull(MerchantUser::withTrashed()->find($user->id)?->deleted_at);
        $this->assertSame([$kept->id], array_column($this->body($this->jsonRequest('GET', "/admin/merchants/{$merchant->id}/users", $token)), 'id'));
        $this->assertSame(401, $this->jsonRequest('GET', '/merchant/dashboard', $merchantToken)->getStatusCode());
        $this->assertNull($this->merchantLogin($user->username));
        $this->assertNotNull($this->merchantLogin($kept->username), '同商户的其他账号不受影响');
        $this->assertNotNull(Merchant::find($merchant->id), '商户本身还在');
        $this->assertTrue(AdminOperationLog::where('action', 'delete_merchant_user')->where('target_id', $merchant->id)->exists());

        $response = $this->jsonRequest('POST', "/admin/merchants/{$merchant->id}/users", $token, ['username' => $user->username, 'password' => 'abc12345']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('已删除的账号也占用账号名', (string) $response->getBody());
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

    private function createOrder(Merchant $merchant, string $status): Order
    {
        return Order::create([
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
    }

    private function merchantLogin(string $username): ?string
    {
        $response = $this->client->request('POST', '/merchant/auth/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['username' => $username, 'password' => self::PASSWORD],
        ]);

        return $this->body($response)['token'] ?? null;
    }
}
