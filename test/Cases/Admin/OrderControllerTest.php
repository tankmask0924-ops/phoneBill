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
use App\Model\ProductRoute;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\Model\SupplierProductOperator;
use App\Network\HttpClient;
use App\Service\MerchantPortal\MerchantPortalService;
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

    public function testListSummaryCoversAllMatchingOrders()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        [$merchant, $product, $sp] = $this->fixture();
        $now = time();
        foreach ([10 => '1.50', 20 => '1.30'] as $seconds => $profit) {
            $order = $this->createOrder($merchant, $product, $sp, Order::STATUS_SUCCESS);
            Order::where('id', $order->id)->update([
                'profit' => $profit,
                'created_at' => date('Y-m-d H:i:s', $now - $seconds),
                'finished_at' => date('Y-m-d H:i:s', $now),
            ]);
        }
        $this->createOrder($merchant, $product, $sp, Order::STATUS_FAILED);
        $this->createOrder($merchant, $product, $sp, Order::STATUS_PROCESSING);

        // 每页 1 条，汇总仍然是全部 4 单
        $list = $this->body($this->jsonRequest('GET', "/admin/orders?merchant_id={$merchant->id}&per_page=1", $token));
        $this->assertCount(1, $list['data']);
        $this->assertSame(4, $list['total']);
        $this->assertSame([
            'order_count' => 4,
            'success_count' => 2,
            'failed_count' => 1,
            'processing_count' => 1,
            'abnormal_count' => 0,
            'success_rate' => '66.66',
            'amount' => '199.00',
            'cost' => '196.00',
            'profit' => '2.80',
            'avg_duration' => 15,
        ], $list['summary']);

        // 跟着筛选条件走；筛不出订单时都是 0，成功率、耗时为空
        $this->assertSame(1, $this->body($this->jsonRequest('GET', "/admin/orders?merchant_id={$merchant->id}&status=failed", $token))['summary']['failed_count']);
        $empty = $this->body($this->jsonRequest('GET', '/admin/orders?merchant_id=999999999', $token))['summary'];
        $this->assertSame(['order_count' => 0, 'success_rate' => null, 'profit' => '0.00', 'avg_duration' => null], array_intersect_key($empty, array_flip(['order_count', 'success_rate', 'profit', 'avg_duration'])));
    }

    public function testFaceValueFilter()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        [$merchant, $product, $sp] = $this->fixture();
        $hundred = $this->createOrder($merchant, $product, $sp, Order::STATUS_SUCCESS);
        $fifty = $this->createOrder($merchant, $product, $sp, Order::STATUS_FAILED);
        Order::where('id', $fifty->id)->update(['face_value' => 50]);
        $ids = fn (array $query) => array_column($this->body($this->jsonRequest('GET', '/admin/orders?' . http_build_query($query + ['merchant_id' => $merchant->id]), $token))['data'], 'id');

        $this->assertSame([$hundred->id], $ids(['face_value' => 100]));
        $this->assertSame([$fifty->id], $ids(['face_value' => 50]));
        $empty = $this->body($this->jsonRequest('GET', "/admin/orders?merchant_id={$merchant->id}&face_value=999", $token));
        $this->assertSame([], $empty['data']);
        $this->assertSame(0, $empty['summary']['order_count']);
        $this->assertContains(100, $this->body($this->jsonRequest('GET', '/admin/orders/filter-options', $token))['face_values']);
    }

    public function testDeleteSupplierAndSupplierProductKeepsOrderHistory()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'supplier.view', 'supplier.manage', 'supplier_product.view', 'supplier_product.manage']));
        [$merchant, $product, $sp] = $this->fixture();
        $supplier = Supplier::find($sp->supplier_id);
        $success = $this->createOrder($merchant, $product, $sp, Order::STATUS_SUCCESS);
        $abnormal = $this->createOrder($merchant, $product, $sp, Order::STATUS_ABNORMAL);

        $this->assertSame(422, $this->jsonRequest('DELETE', "/admin/suppliers/{$supplier->id}", $token)->getStatusCode(), '名下还有供应商商品');
        $this->assertSame(422, $this->jsonRequest('DELETE', "/admin/supplier-products/{$sp->id}", $token)->getStatusCode(), '还绑定在平台商品上');
        ProductRoute::where('supplier_product_id', $sp->id)->delete();
        $this->assertSame(422, $this->jsonRequest('DELETE', "/admin/supplier-products/{$sp->id}", $token)->getStatusCode(), '还有提交了没出结果的单');
        OrderAttempt::where('order_id', $abnormal->id)->update(['status' => OrderAttempt::STATUS_FAILED]);

        $deleted = $this->jsonRequest('DELETE', "/admin/supplier-products/{$sp->id}", $token);
        $this->assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        $this->assertNull(SupplierProduct::find($sp->id));
        $this->assertNotNull(SupplierProduct::withTrashed()->find($sp->id), '软删除');
        $this->assertSame(0, SupplierProductOperator::where('supplier_product_id', $sp->id)->count());
        $this->assertSame(404, $this->jsonRequest('DELETE', "/admin/supplier-products/{$sp->id}", $token)->getStatusCode());

        $this->assertSame(200, $this->jsonRequest('DELETE', "/admin/suppliers/{$supplier->id}", $token)->getStatusCode());
        $this->assertSame(0, $this->body($this->jsonRequest('GET', '/admin/suppliers?keyword=' . $supplier->code, $token))['total']);
        $this->assertNotContains($supplier->id, array_column($this->body($this->jsonRequest('GET', '/admin/supplier-products/supplier-options', $token)), 'id'));

        // 历史订单照常显示名字，订单筛选里还能选到（标「已删除」）
        $orders = $this->body($this->jsonRequest('GET', "/admin/orders?merchant_id={$merchant->id}&status=success", $token));
        $this->assertSame($supplier->name, $orders['data'][0]['supplier_name']);
        $this->assertSame($sp->name, $orders['data'][0]['supplier_product_name']);
        $detail = $this->body($this->jsonRequest('GET', "/admin/orders/{$success->id}", $token));
        $this->assertSame($supplier->name, $detail['attempts'][0]['supplier_name']);
        $this->assertSame($sp->name, $detail['attempts'][0]['supplier_product_name']);
        $options = $this->body($this->jsonRequest('GET', '/admin/orders/filter-options', $token));
        $this->assertContains(['id' => $supplier->id, 'name' => $supplier->name . '（已删除）'], $options['suppliers']);

        // 编码和名称还占着
        $again = $this->jsonRequest('POST', '/admin/suppliers', $token, ['name' => $this->uniq('供应商'), 'code' => $supplier->code, 'driver' => 'mock', 'config' => ['result' => 'success']]);
        $this->assertSame(422, $again->getStatusCode());
        $this->assertStringContainsString('已删除', (string) $again->getBody());
    }

    public function testDashboardNeedsOrderViewAndCountsToday()
    {
        $this->assertSame(403, $this->jsonRequest('GET', '/admin/dashboard', $this->loginAs($this->createAdminWithPermissions(['merchant.view'])))->getStatusCode());
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        $before = $this->body($this->jsonRequest('GET', '/admin/dashboard', $token));
        $this->assertSame(['sales', 'merchant_count', 'order_count', 'profit'], array_keys($before['total']));

        [$merchant, $product, $sp] = $this->fixture();
        $success = $this->createOrder($merchant, $product, $sp, Order::STATUS_SUCCESS);
        Order::where('id', $success->id)->update(['profit' => '1.50']);
        $this->createOrder($merchant, $product, $sp, Order::STATUS_FAILED);

        $after = $this->body($this->jsonRequest('GET', '/admin/dashboard', $token))['today'];
        $this->assertSame($before['today']['order_count'] + 2, $after['order_count']);
        $this->assertSame($before['today']['success_count'] + 1, $after['success_count']);
        $this->assertSame(bcadd($before['today']['sales'], '99.50', 2), $after['sales'], '只算成功的');
        $this->assertSame(bcadd($before['today']['profit'], '1.50', 2), $after['profit']);
    }

    public function testBatchMobileFilter()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        [$merchant, $product, $sp] = $this->fixture();
        $a = $this->createOrder($merchant, $product, $sp, Order::STATUS_SUCCESS);
        $b = $this->createOrder($merchant, $product, $sp, Order::STATUS_FAILED);
        $c = $this->createOrder($merchant, $product, $sp, Order::STATUS_SUCCESS);
        $ids = fn (string $mobile) => array_column($this->body($this->jsonRequest('GET', '/admin/orders?' . http_build_query(['mobile' => $mobile]), $token))['data'], 'id');

        // 换行、中英文逗号、空格分隔都行，不像手机号的忽略；一个号码时照旧
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids("{$a->mobile}\n{$b->mobile}，abc"));
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $ids("{$a->mobile} {$c->mobile},{$a->mobile}"));
        $this->assertSame([$b->id], $ids($b->mobile));
        $this->assertSame([], $ids('abc'), '填了但没有手机号：查不到，不是忽略条件');

        $tooMany = implode("\n", array_map(static fn ($i) => (string) (13800000000 + $i), range(1, 201)));
        $this->assertSame(422, $this->jsonRequest('GET', '/admin/orders?' . http_build_query(['mobile' => $tooMany]), $token)->getStatusCode());
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
        $this->assertSame('1.50', $ok['profit'], '人工置成功也写利润');
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
        $order->update(['profit' => '1.50']);
        $manager = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.manage']));
        $this->assertSame(403, $this->jsonRequest('POST', "/admin/orders/{$order->id}/reverse", $manager, ['remark' => 'x'])->getStatusCode());

        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.reverse']));
        $balance = Merchant::find($merchant->id)->balance;
        $reversed = $this->body($this->jsonRequest('POST', "/admin/orders/{$order->id}/reverse", $token, ['remark' => '运营商撤销']));
        $this->assertSame('failed', $reversed['status']);
        $this->assertStringContainsString('冲正：运营商撤销', $reversed['fail_reason']);
        $this->assertNull($reversed['profit'], '冲正后不算利润');
        $this->assertSame('98.00', $reversed['cost_price'], '成本留着对账');
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
        $notified = $this->body($this->jsonRequest('POST', "/admin/orders/{$done->id}/notify", $token, ['result' => 'success', 'remark' => '商户要求重推']));
        $this->assertSame('pending', $notified['notify_status']);
        $this->assertNotNull($notified['manual_notified_at']);
        $this->jsonRequest('POST', "/admin/orders/{$done->id}/notify", $token, ['result' => 'success', 'remark' => '再推一次']);
        $this->assertSame([
            ['order_id' => $done->id, 'attempt_no' => 1, 'delay' => 0, 'round' => 1],
            ['order_id' => $done->id, 'attempt_no' => 1, 'delay' => 0, 'round' => 2],
        ], $this->dispatcher->notifies);
    }

    public function testManualNotifyNeedsResultAndRemark()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.manage']));
        [$merchant, $product, $sp] = $this->fixture();
        $order = $this->createOrder($merchant, $product, $sp, Order::STATUS_PROCESSING);

        foreach ([['remark' => 'x'], ['result' => 'processing', 'remark' => 'x'], ['result' => 'success'], ['result' => 'success', 'remark' => ' ']] as $bad) {
            $this->assertSame(422, $this->jsonRequest('POST', "/admin/orders/{$order->id}/notify", $token, $bad)->getStatusCode(), json_encode($bad));
        }
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame([], $this->dispatcher->notifies);
    }

    public function testManualNotifySettlesOrderToChosenResult()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.manage']));
        [$merchant, $product, $sp] = $this->fixture();
        $http = ApplicationContext::getContainer()->get(HttpClient::class);

        // 充值中 → 成功：按最后一次提交记账，提交记为成功，不动余额，推 success；之后上游再来的失败只记录
        $processing = $this->createOrder($merchant, $product, $sp, Order::STATUS_PROCESSING);
        $balance = Merchant::find($merchant->id)->balance;
        $ok = $this->body($this->jsonRequest('POST', "/admin/orders/{$processing->id}/notify", $token, ['result' => 'success', 'remark' => '上游后台已到账']));
        $this->assertSame('success', $ok['status']);
        $this->assertSame('98.00', $ok['cost_price']);
        $this->assertSame('1.50', $ok['profit']);
        $this->assertNotNull($ok['finished_at']);
        $this->assertSame('success', $ok['attempts'][0]['status']);
        $this->assertStringContainsString('手动回调置为成功', $ok['attempts'][0]['message']);
        $this->assertSame($balance, Merchant::find($merchant->id)->balance);
        $this->assertSame([['order_id' => $processing->id, 'attempt_no' => 1, 'delay' => 0, 'round' => 1]], $this->dispatcher->notifies);
        make(MerchantNotifyService::class)->notify($processing->id, 1, 1);
        $this->assertSame('success', end($http->requests)['payload']['status']);

        // 异常的人工处理单 → 失败：退款，处理中的提交置失败，商户马上看到失败
        $abnormal = $this->createOrder($merchant, $product, $sp, Order::STATUS_ABNORMAL);
        $abnormal->update(['manual_only' => true]);
        $balance = Merchant::find($merchant->id)->balance;
        $failed = $this->body($this->jsonRequest('POST', "/admin/orders/{$abnormal->id}/notify", $token, ['result' => 'failed', 'remark' => '上游确认没充']));
        $this->assertSame('failed', $failed['status']);
        $this->assertStringContainsString('上游确认没充', $failed['fail_reason']);
        $this->assertSame('failed', $failed['attempts'][0]['status']);
        $this->assertSame(bcadd($balance, '99.50', 2), Merchant::find($merchant->id)->balance);
        $this->assertSame('failed', make(OrderService::class)->present(Order::find($abnormal->id))['status']);
        $attempt = OrderAttempt::where('order_id', $abnormal->id)->first();

        // 失败 → 成功：重新扣款
        $balance = Merchant::find($merchant->id)->balance;
        $back = $this->body($this->jsonRequest('POST', "/admin/orders/{$abnormal->id}/notify", $token, ['result' => 'success', 'remark' => '其实充上了']));
        $this->assertSame('success', $back['status']);
        $this->assertNull($back['fail_reason']);
        $this->assertSame(bcsub($balance, '99.50', 2), Merchant::find($merchant->id)->balance);
        $this->assertSame(['order_pay', 'order_refund', 'order_pay'], array_column($back['balance_logs'], 'type'));
        $this->assertSame(OrderAttempt::STATUS_FAILED, OrderAttempt::find($attempt->id)->status, '已结束的提交不改');

        // 同样的结果再回调：只推，不动钱
        $balance = Merchant::find($merchant->id)->balance;
        $this->assertSame(200, $this->jsonRequest('POST', "/admin/orders/{$abnormal->id}/notify", $token, ['result' => 'success', 'remark' => '重推'])->getStatusCode());
        $this->assertSame($balance, Merchant::find($merchant->id)->balance);

        // 失败改成功、商户可用额度不够：钱已经花在供应商那边，照扣，余额变成负数（即使超出授信）
        $poor = $this->createOrder($merchant, $product, $sp, Order::STATUS_FAILED);
        Merchant::where('id', $merchant->id)->update(['balance' => '1.00', 'credit_limit' => '0.00']);
        $this->assertSame('success', $this->body($this->jsonRequest('POST', "/admin/orders/{$poor->id}/notify", $token, ['result' => 'success', 'remark' => 'x']))['status']);
        $this->assertSame(bcsub('1.00', $poor->sale_price, 2), Merchant::find($merchant->id)->balance);
    }

    public function testManualNotifySuccessToFailedNeedsReversePermission()
    {
        [$merchant, $product, $sp] = $this->fixture();
        $order = $this->createOrder($merchant, $product, $sp, Order::STATUS_SUCCESS);
        $manager = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.manage']));
        $this->assertSame(403, $this->jsonRequest('POST', "/admin/orders/{$order->id}/notify", $manager, ['result' => 'failed', 'remark' => 'x'])->getStatusCode());
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
        $this->assertSame([], $this->dispatcher->notifies);

        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.manage', 'order.reverse']));
        $balance = Merchant::find($merchant->id)->balance;
        $failed = $this->body($this->jsonRequest('POST', "/admin/orders/{$order->id}/notify", $token, ['result' => 'failed', 'remark' => '运营商撤销']));
        $this->assertSame('failed', $failed['status']);
        $this->assertNull($failed['profit']);
        $this->assertSame(bcadd($balance, '99.50', 2), Merchant::find($merchant->id)->balance);
        $this->assertSame([$order->id], array_column($this->dispatcher->notifies, 'order_id'));
    }

    public function testBatchManualNotify()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.manage']));
        [$merchant, $product, $sp] = $this->fixture();
        $processing = $this->createOrder($merchant, $product, $sp, Order::STATUS_PROCESSING);
        $abnormal = $this->createOrder($merchant, $product, $sp, Order::STATUS_ABNORMAL);
        $success = $this->createOrder($merchant, $product, $sp, Order::STATUS_SUCCESS);
        $balance = Merchant::find($merchant->id)->balance;

        foreach ([['ids' => [], 'result' => 'failed', 'remark' => 'x'], ['ids' => [$processing->id], 'remark' => 'x'], ['ids' => [$processing->id], 'result' => 'failed']] as $bad) {
            $this->assertSame(422, $this->jsonRequest('POST', '/admin/orders/batch-notify', $token, $bad)->getStatusCode(), json_encode($bad));
        }

        // 成功的订单回调失败要冲正权限：这一笔失败，其他照常处理
        $result = $this->body($this->jsonRequest('POST', '/admin/orders/batch-notify', $token, [
            'ids' => [$processing->id, $abnormal->id, $success->id, 999999999],
            'result' => 'failed',
            'remark' => '上游批量确认没充',
        ]));
        $this->assertSame(4, $result['total']);
        $this->assertSame(2, $result['succeeded']);
        $this->assertSame([$success->id, 999999999], array_column($result['failures'], 'id'));
        $this->assertSame([$success->order_no, null], array_column($result['failures'], 'order_no'));
        $this->assertStringContainsString('冲正', $result['failures'][0]['message']);

        $this->assertSame(Order::STATUS_FAILED, Order::find($processing->id)->status);
        $this->assertSame(Order::STATUS_FAILED, Order::find($abnormal->id)->status);
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($success->id)->status);
        $this->assertSame(bcadd($balance, '199.00', 2), Merchant::find($merchant->id)->balance);
        $this->assertSame([$processing->id, $abnormal->id], array_column($this->dispatcher->notifies, 'order_id'));
    }

    public function testListFlagsAbnormalOrders()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        [$merchant, $product, $sp] = $this->fixture();
        $normal = $this->createOrder($merchant, $product, $sp, Order::STATUS_PROCESSING);
        $unclear = $this->createOrder($merchant, $product, $sp, Order::STATUS_PROCESSING);
        OrderAttempt::where('order_id', $unclear->id)->update(['message' => '下单未确认受理，等待查单：code=500']);
        $abnormal = $this->createOrder($merchant, $product, $sp, Order::STATUS_ABNORMAL);
        $abnormal->update(['manual_only' => true]);

        $rows = array_column($this->body($this->jsonRequest('GET', "/admin/orders?merchant_id={$merchant->id}", $token))['data'], null, 'id');
        $this->assertFalse($rows[$normal->id]['abnormal']);
        $this->assertNull($rows[$normal->id]['abnormal_reason']);
        $this->assertTrue($rows[$unclear->id]['abnormal'], '还在充值中但上游返回不明确');
        $this->assertSame('processing', $rows[$unclear->id]['status']);
        $this->assertStringContainsString('下单未确认受理', $rows[$unclear->id]['abnormal_reason']);
        $this->assertTrue($rows[$abnormal->id]['abnormal']);
        $this->assertStringContainsString('接口异常', $rows[$abnormal->id]['abnormal_reason']);

        $summary = $this->body($this->jsonRequest('GET', "/admin/orders?merchant_id={$merchant->id}", $token))['summary'];
        $this->assertSame(1, $summary['abnormal_count']);

        // 商户后台：上游不明确的和异常的显示为异常；开放接口查单、回调仍是 processing
        $merchantRows = array_column(make(MerchantPortalService::class)->orders(Merchant::find($merchant->id), [])['data'], 'status', 'id');
        $this->assertSame('processing', $merchantRows[$normal->id]);
        $this->assertSame('abnormal', $merchantRows[$unclear->id]);
        $this->assertSame('abnormal', $merchantRows[$abnormal->id]);
        foreach ([$unclear, $abnormal] as $o) {
            $this->assertSame('processing', make(OrderService::class)->present(Order::find($o->id))['status']);
        }
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
        $this->body($this->jsonRequest('POST', "/admin/orders/{$order->id}/notify", $token, ['result' => 'failed', 'remark' => '上游核实未充值']));
        $this->assertSame([['order_id' => $order->id, 'attempt_no' => 1, 'delay' => 0, 'round' => 1]], $this->dispatcher->notifies);
        $this->assertSame('failed', make(OrderService::class)->present(Order::find($order->id))['status']);
        $this->assertSame(1, MerchantBalanceLog::where('order_id', $order->id)->where('type', 'order_refund')->count(), '已经失败的不重复退款');

        // 普通订单手动回调后：上游再来的结果只记到提交上，不改订单、不推给商户；矛盾时详情标红
        $normal = $this->createOrder($merchant, $product, $sp, Order::STATUS_FAILED);
        $this->jsonRequest('POST', "/admin/orders/{$normal->id}/notify", $token, ['result' => 'failed', 'remark' => '重推']);
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
