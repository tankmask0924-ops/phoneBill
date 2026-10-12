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
use App\Model\OrderNotifyLog;
use App\Model\Product;
use App\Model\SupplierProduct;
use App\Network\HttpClient;
use App\Service\Order\MerchantNotifyService;
use App\Service\Order\OrderDispatcher;
use App\Service\Order\OrderProcessService;
use App\Service\Order\OrderService;
use App\Supplier\RechargeResult;
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

    public function testQueryAndManualNotify()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.manage']));
        [$merchant, $product, $sp] = $this->fixture();
        $processing = $this->createOrder($merchant, $product, $sp, Order::STATUS_PROCESSING);
        $done = $this->createOrder($merchant, $product, $sp, Order::STATUS_SUCCESS);

        // 模拟供应商查单返回成功
        $queried = $this->body($this->jsonRequest('POST', "/admin/orders/{$processing->id}/query", $token));
        $this->assertSame('success', $queried['status']);
        $this->assertSame([['attempt_no' => $processing->order_no . '01', 'status' => 'success', 'message' => null]], $queried['query_results']);

        // 异常订单查到供应商失败：提交记为失败，订单保持异常、不退款，等人工置为失败；返回里告诉前端查到的是失败
        $failingSp = $this->createSupplierProduct($this->createSupplier(['*'], 'active', 'failed'), ['cmcc']);
        $abnormal = $this->createOrder($merchant, $product, $failingSp, Order::STATUS_ABNORMAL);
        $balance = Merchant::find($merchant->id)->balance;
        $result = $this->body($this->jsonRequest('POST', "/admin/orders/{$abnormal->id}/query", $token));
        $this->assertSame('abnormal', $result['status']);
        $this->assertSame('failed', $result['query_results'][0]['status']);
        $this->assertSame(OrderAttempt::STATUS_FAILED, OrderAttempt::where('order_id', $abnormal->id)->first()->status);
        $this->assertSame($balance, Merchant::find($merchant->id)->balance);
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/orders/{$done->id}/query", $token)->getStatusCode(), '没有处理中的提交');

        // 手动回调：每次开新一轮通知
        $this->dispatcher->notifies = [];
        $notified = $this->body($this->jsonRequest('POST', "/admin/orders/{$done->id}/notify", $token));
        $this->assertSame('pending', $notified['notify_status']);
        $this->assertNotNull($notified['manual_notified_at']);
        $this->jsonRequest('POST', "/admin/orders/{$done->id}/notify", $token);
        $this->assertSame([
            ['order_id' => $done->id, 'attempt_no' => 1, 'delay' => 0, 'round' => 1],
            ['order_id' => $done->id, 'attempt_no' => 1, 'delay' => 0, 'round' => 2],
        ], $this->dispatcher->notifies);
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/orders/{$abnormal->id}/notify", $token)->getStatusCode(), '没有结果的订单不能回调');
    }

    public function testManualOnlyOrderNeedsManualNotifyAndLaterUpstreamResultsAreHeld()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.manage']));
        [$merchant, $product, $sp] = $this->fixture();
        $order = $this->createOrder($merchant, $product, $sp, Order::STATUS_ABNORMAL);
        $order->update(['manual_only' => true]);
        $this->assertStringContainsString('人工处理单', $this->body($this->jsonRequest('GET', "/admin/orders/{$order->id}", $token))['warnings'][0]);

        // 人工置为失败：退款，但不自动通知商户，商户查单还是处理中
        $this->dispatcher->notifies = [];
        $failed = $this->body($this->jsonRequest('POST', "/admin/orders/{$order->id}/fail", $token, ['remark' => '上游核实未充值']));
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('none', $failed['notify_status']);
        $this->assertSame([], $this->dispatcher->notifies);
        $this->assertSame('processing', make(OrderService::class)->present(Order::find($order->id))['status']);
        $this->assertStringContainsString('还没有回调', $failed['warnings'][0]);

        // 手动回调后商户才看到失败
        $this->body($this->jsonRequest('POST', "/admin/orders/{$order->id}/notify", $token));
        $this->assertSame([['order_id' => $order->id, 'attempt_no' => 1, 'delay' => 0, 'round' => 1]], $this->dispatcher->notifies);
        $this->assertSame('failed', make(OrderService::class)->present(Order::find($order->id))['status']);

        // 普通订单手动回调后：上游再来的结果只记到提交上，不改订单、不推给商户；矛盾时详情标红
        $normal = $this->createOrder($merchant, $product, $sp, Order::STATUS_FAILED);
        $this->jsonRequest('POST', "/admin/orders/{$normal->id}/notify", $token);
        $this->dispatcher->notifies = [];
        $attempt = OrderAttempt::where('order_id', $normal->id)->first();
        make(OrderProcessService::class)->applyResult($attempt->id, RechargeResult::success('UP1'));
        $this->assertSame(OrderAttempt::STATUS_SUCCESS, OrderAttempt::find($attempt->id)->status);
        $this->assertSame(Order::STATUS_FAILED, Order::find($normal->id)->status);
        $this->assertSame([], $this->dispatcher->notifies);
        $this->assertStringContainsString('核对资金', $this->body($this->jsonRequest('GET', "/admin/orders/{$normal->id}", $token))['warnings'][0]);

        // 手动回调之前排队的通知（旧轮次）作废，新轮次照常发
        make(MerchantNotifyService::class)->notify($normal->id, 1, 0);
        $this->assertSame(0, OrderNotifyLog::where('order_id', $normal->id)->count());
        make(MerchantNotifyService::class)->notify($normal->id, 1, 1);
        $this->assertSame(1, OrderNotifyLog::where('order_id', $normal->id)->count());
    }

    public function testResupplyAbnormalOrder()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.manage']));
        [$merchant, , $sp] = $this->fixture();
        $other = $this->createSupplierProduct($this->createSupplier(), ['cmcc'], 100, '98.50');
        $product = $this->createProduct([$sp->id => 1, $other->id => 2]);
        $order = $this->createOrder($merchant, $product, $sp, Order::STATUS_ABNORMAL);
        $order->update(['manual_only' => true]);

        // 可选的供应商商品：全部候选，标出试过的
        $options = $this->body($this->jsonRequest('GET', "/admin/orders/{$order->id}/resupply-options", $token));
        $this->assertSame([$sp->id, $other->id], array_column($options, 'supplier_product_id'));
        $this->assertSame([true, false], array_column($options, 'tried'));

        foreach ([['supplier_product_id' => $sp->id], ['supplier_product_id' => 999999999, 'remark' => 'x']] as $bad) {
            $this->assertSame(422, $this->jsonRequest('POST', "/admin/orders/{$order->id}/resupply", $token, $bad)->getStatusCode(), json_encode($bad));
        }
        $done = $this->createOrder($merchant, $product, $sp, Order::STATUS_SUCCESS);
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/orders/{$done->id}/resupply", $token, ['supplier_product_id' => $sp->id, 'remark' => 'x'])->getStatusCode(), '只有异常订单能补单');

        $balance = Merchant::find($merchant->id)->balance;

        // 补给试过的同一家：原提交记为人工确认未成功，订单回到处理中、不再是人工处理单，推一个指定供应商商品的提交任务
        $this->dispatcher->submits = [];
        $resupplied = $this->body($this->jsonRequest('POST', "/admin/orders/{$order->id}/resupply", $token, ['supplier_product_id' => $sp->id, 'remark' => '上游确认没有这笔单']));
        $this->assertSame('processing', $resupplied['status']);
        $this->assertFalse($resupplied['manual_only']);
        $this->assertSame(OrderAttempt::STATUS_FAILED, $resupplied['attempts'][0]['status']);
        $this->assertStringContainsString('人工确认上游未成功', $resupplied['attempts'][0]['message']);
        $this->assertSame([['order_id' => $order->id, 'delay' => 0, 'preferred' => $sp->id]], $this->dispatcher->submits);

        // 提交任务执行：试过的那家也会再提交一次；同一笔订单，不重复扣款，成功后正常通知商户
        $this->dispatcher->notifies = [];
        make(OrderProcessService::class)->submit($order->id, $sp->id);
        $attempts = OrderAttempt::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertSame([$sp->id, $sp->id], $attempts->pluck('supplier_product_id')->all());
        $this->assertSame($order->order_no . '02', $attempts[1]->attempt_no);
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
        $this->assertSame([$order->id], array_column($this->dispatcher->notifies, 'order_id'));
        $this->assertSame($balance, Merchant::find($merchant->id)->balance);
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
