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

use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\Product;
use App\Model\SupplierProduct;
use App\Network\HttpClient;
use App\Service\Order\OrderDispatcher;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\HttpTestCase;
use HyperfTest\Support\FakeHttpClient;
use HyperfTest\Support\FakeOrderDispatcher;

use function Hyperf\Support\make;

/**
 * 订单中心：列表筛选、详情、导出，人工查单 / 置成功 / 置失败 / 冲正 / 重发通知。
 *
 * @internal
 * @coversNothing
 */
class OrderControllerTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

    private FakeOrderDispatcher $dispatcher;

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->dispatcher = new FakeOrderDispatcher();
        ApplicationContext::getContainer()->set(OrderDispatcher::class, $this->dispatcher);
        ApplicationContext::getContainer()->set(HttpClient::class, new FakeHttpClient());
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testListFilterAndDetail()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        [$merchant, $product, $sp] = $this->fixture();
        $success = $this->createOrder($merchant, $product, $sp, Order::STATUS_SUCCESS);
        $abnormal = $this->createOrder($merchant, $product, $sp, Order::STATUS_ABNORMAL);

        $all = $this->body($this->jsonRequest('GET', "/admin/orders?merchant_id={$merchant->id}", $token));
        $this->assertSame([$abnormal->id, $success->id], array_column($all['data'], 'id'), '新的在前');
        $this->assertSame($merchant->name, $all['data'][0]['merchant_name']);

        $filtered = $this->body($this->jsonRequest('GET', "/admin/orders?merchant_id={$merchant->id}&status=abnormal", $token));
        $this->assertSame([$abnormal->id], array_column($filtered['data'], 'id'));
        $byNo = $this->body($this->jsonRequest('GET', '/admin/orders?keyword=' . $success->merchant_order_no, $token));
        $this->assertSame([$success->id], array_column($byNo['data'], 'id'));
        $this->assertSame([], $this->body($this->jsonRequest('GET', '/admin/orders?keyword=no_such_order', $token))['data']);

        $detail = $this->body($this->jsonRequest('GET', "/admin/orders/{$success->id}", $token));
        $this->assertSame($success->order_no, $detail['order_no']);
        $this->assertCount(1, $detail['attempts']);
        $this->assertSame($sp->name, $detail['attempts'][0]['supplier_product_name']);
        $this->assertSame(['order_pay'], array_column($detail['balance_logs'], 'type'));

        $csv = (string) $this->jsonRequest('GET', "/admin/orders/export?merchant_id={$merchant->id}", $token)->getBody();
        $this->assertStringStartsWith("\xEF\xBB\xBF平台订单号", $csv);
        $this->assertStringContainsString($success->order_no, $csv);
        $this->assertStringContainsString($abnormal->order_no, $csv);

        $this->assertArrayHasKey('merchants', $this->body($this->jsonRequest('GET', '/admin/orders/filter-options', $token)));
        $this->assertSame(403, $this->jsonRequest('POST', "/admin/orders/{$abnormal->id}/success", $token, ['remark' => 'x'])->getStatusCode());
    }

    public function testConfirmAbnormalOrders()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.manage']));
        [$merchant, $product, $sp] = $this->fixture();
        $toSuccess = $this->createOrder($merchant, $product, $sp, Order::STATUS_ABNORMAL);
        $toFail = $this->createOrder($merchant, $product, $sp, Order::STATUS_ABNORMAL);
        $done = $this->createOrder($merchant, $product, $sp, Order::STATUS_SUCCESS);
        $balance = Merchant::find($merchant->id)->balance;

        $this->assertSame(422, $this->jsonRequest('POST', "/admin/orders/{$toSuccess->id}/success", $token, ['remark' => ''])->getStatusCode(), '必须写备注');
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/orders/{$done->id}/fail", $token, ['remark' => 'x'])->getStatusCode(), '只有异常订单');

        $ok = $this->body($this->jsonRequest('POST', "/admin/orders/{$toSuccess->id}/success", $token, ['remark' => '供应商后台确认到账']));
        $this->assertSame('success', $ok['status']);
        $this->assertSame('98.00', $ok['cost_price']);
        $this->assertSame('success', $ok['attempts'][0]['status']);
        $this->assertSame($balance, Merchant::find($merchant->id)->balance, '置成功不动余额');

        $failed = $this->body($this->jsonRequest('POST', "/admin/orders/{$toFail->id}/fail", $token, ['remark' => '供应商确认未到账']));
        $this->assertSame('failed', $failed['status']);
        $this->assertStringContainsString('供应商确认未到账', $failed['fail_reason']);
        $this->assertSame('failed', $failed['attempts'][0]['status']);
        $this->assertSame(bcadd($balance, '99.50', 2), Merchant::find($merchant->id)->balance, '置失败退款');
        $this->assertSame(['order_pay', 'order_refund'], array_column($failed['balance_logs'], 'type'));

        $notified = array_column($this->dispatcher->notifies, 'order_id');
        $this->assertContains($toSuccess->id, $notified);
        $this->assertContains($toFail->id, $notified);

        // 重复操作不会重复退款
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/orders/{$toFail->id}/fail", $token, ['remark' => 'x'])->getStatusCode());
        $this->assertSame(1, MerchantBalanceLog::where('order_id', $toFail->id)->where('type', 'order_refund')->count());
    }

    public function testReverseNeedsItsOwnPermissionAndRefunds()
    {
        [$merchant, $product, $sp] = $this->fixture();
        $order = $this->createOrder($merchant, $product, $sp, Order::STATUS_SUCCESS);
        $manager = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.manage']));
        $this->assertSame(403, $this->jsonRequest('POST', "/admin/orders/{$order->id}/reverse", $manager, ['remark' => 'x'])->getStatusCode());

        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.reverse']));
        $balance = Merchant::find($merchant->id)->balance;
        $reversed = $this->body($this->jsonRequest('POST', "/admin/orders/{$order->id}/reverse", $token, ['remark' => '运营商撤销']));
        $this->assertSame('failed', $reversed['status']);
        $this->assertStringContainsString('冲正：运营商撤销', $reversed['fail_reason']);
        $this->assertSame(bcadd($balance, '99.50', 2), Merchant::find($merchant->id)->balance);
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/orders/{$order->id}/reverse", $token, ['remark' => 'again'])->getStatusCode());
    }

    public function testQueryAndResendNotify()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.manage']));
        [$merchant, $product, $sp] = $this->fixture();
        $processing = $this->createOrder($merchant, $product, $sp, Order::STATUS_PROCESSING);
        $done = $this->createOrder($merchant, $product, $sp, Order::STATUS_SUCCESS);

        // 模拟供应商查单返回成功
        $queried = $this->body($this->jsonRequest('POST', "/admin/orders/{$processing->id}/query", $token));
        $this->assertSame('success', $queried['status']);
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/orders/{$done->id}/query", $token)->getStatusCode(), '没有处理中的提交');

        $this->dispatcher->notifies = [];
        $this->assertSame('pending', $this->body($this->jsonRequest('POST', "/admin/orders/{$done->id}/notify", $token))['notify_status']);
        $this->assertSame([['order_id' => $done->id, 'attempt_no' => 1, 'delay' => 0]], $this->dispatcher->notifies);
    }

    /**
     * @return array{0: Merchant, 1: Product, 2: SupplierProduct}
     */
    private function fixture(): array
    {
        $sp = $this->createSupplierProduct($this->createSupplier(), ['cmcc'], 100, '98.00');
        $product = $this->createProduct([$sp->id => 1]);

        return [$this->createMerchant('500.00'), $product, $sp];
    }

    /**
     * 直接在库里造一笔已扣款的订单和一次提交：成功的提交成功，其余的提交处理中。
     */
    private function createOrder(Merchant $merchant, Product $product, SupplierProduct $sp, string $status): Order
    {
        $orderNo = date('YmdHis') . random_int(100000, 999999);
        $final = in_array($status, [Order::STATUS_SUCCESS, Order::STATUS_FAILED], true);
        $order = Order::create([
            'order_no' => $orderNo,
            'merchant_id' => $merchant->id,
            'merchant_order_no' => $this->uniq('M'),
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'mobile' => '1009' . random_int(1000000, 9999999),
            'operator' => 'cmcc',
            'province' => '广东',
            'face_value' => 100,
            'sale_price' => '99.50',
            'cost_price' => $status === Order::STATUS_SUCCESS ? '98.00' : null,
            'supplier_id' => $status === Order::STATUS_SUCCESS ? $sp->supplier_id : null,
            'supplier_product_id' => $status === Order::STATUS_SUCCESS ? $sp->id : null,
            'status' => $status,
            'notify_status' => $final ? Order::NOTIFY_SUCCESS : Order::NOTIFY_NONE,
            'finished_at' => $final ? date('Y-m-d H:i:s') : null,
        ]);
        OrderAttempt::create([
            'order_id' => $order->id,
            'attempt_no' => $orderNo . '01',
            'supplier_id' => $sp->supplier_id,
            'supplier_product_id' => $sp->id,
            'cost_price' => '98.00',
            'status' => $status === Order::STATUS_SUCCESS ? OrderAttempt::STATUS_SUCCESS : OrderAttempt::STATUS_PROCESSING,
            'submitted_at' => date('Y-m-d H:i:s'),
        ]);
        $merchant->refresh();
        $after = bcsub($merchant->balance, '99.50', 2);
        $merchant->update(['balance' => $after]);
        MerchantBalanceLog::create([
            'merchant_id' => $merchant->id, 'type' => 'order_pay', 'amount' => '-99.50', 'balance_after' => $after,
            'order_id' => $order->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $order;
    }
}
