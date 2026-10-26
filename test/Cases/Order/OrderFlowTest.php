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

namespace HyperfTest\Cases\Order;

use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MobileBlacklist;
use App\Model\MobileSegment;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderNotifyLog;
use App\Model\Product;
use App\Model\Supplier;
use App\Network\HttpClient;
use App\Security\OpenApiSigner;
use App\Service\MerchantPortal\MerchantPortalService;
use App\Service\Order\MerchantNotifyService;
use App\Service\Order\OrderDispatcher;
use App\Service\Order\OrderProcessService;
use App\Service\Order\OrderService;
use App\Service\Product\CategoryMobileLimitService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\Cases\Admin\CreatesCatalog;
use HyperfTest\HttpTestCase;
use HyperfTest\Support\FakeHttpClient;
use HyperfTest\Support\FakeOrderDispatcher;

use function Hyperf\Support\make;

/**
 * 下单到出结果的完整流程（docs/project.md 3.1 ~ 3.3）：开放接口鉴权、各种拒单、扣款、
 * 提交供应商、失败换供应商、全部失败退款、回调、查单、转异常、通知商户。
 *
 * 队列和对外 HTTP 都换成假的，测试里直接调处理逻辑。
 *
 * @internal
 * @coversNothing
 */
class OrderFlowTest extends HttpTestCase
{
    use CreatesCatalog;

    private FakeOrderDispatcher $dispatcher;

    private FakeHttpClient $http;

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->dispatcher = new FakeOrderDispatcher();
        $this->http = new FakeHttpClient();
        ApplicationContext::getContainer()->set(OrderDispatcher::class, $this->dispatcher);
        ApplicationContext::getContainer()->set(HttpClient::class, $this->http);
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        $this->cleanUpCatalog();
        parent::tearDown();
    }

    public function testSuccessfulOrderChargesOnceAndNotifiesMerchant()
    {
        [$merchant, $product, $mobile] = $this->scenario([['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '98.80']]);

        $created = $this->api($merchant, 'POST', '/open/v1/recharge', ['product_code' => $product->code, 'mobile' => $mobile, 'merchant_order_no' => 'M001']);
        $this->assertSame('OK', $created['code'], json_encode($created, JSON_UNESCAPED_UNICODE));
        $this->assertSame('processing', $created['data']['status']);
        $this->assertSame('99.50', $created['data']['amount']);
        $this->assertSame('100.50', Merchant::find($merchant->id)->balance);
        $order = Order::where('order_no', $created['data']['order_no'])->first();
        $this->assertSame([['order_id' => $order->id, 'delay' => 0]], $this->dispatcher->submits);

        // 同一个商户订单号再提交：返回原订单，不重复扣款
        $again = $this->api($merchant, 'POST', '/open/v1/recharge', ['product_code' => $product->code, 'mobile' => $mobile, 'merchant_order_no' => 'M001']);
        $this->assertSame($created['data']['order_no'], $again['data']['order_no']);
        $this->assertSame('100.50', Merchant::find($merchant->id)->balance);

        $this->process()->submit($order->id);
        $order = Order::find($order->id);
        $this->assertSame(Order::STATUS_SUCCESS, $order->status);
        $this->assertSame('98.80', $order->cost_price);
        $this->assertSame('0.70', $order->profit, '利润 = 扣款 99.50 - 成本 98.80');
        $this->assertSame([['order_id' => $order->id, 'attempt_no' => 1, 'delay' => 0]], $this->dispatcher->notifies);

        make(MerchantNotifyService::class)->notify($order->id, 1);
        $this->assertSame(Order::NOTIFY_SUCCESS, Order::find($order->id)->notify_status);
        $payload = $this->http->requests[0]['payload'];
        $this->assertSame('success', $payload['status']);
        $this->assertSame('M001', $payload['merchant_order_no']);
        $this->assertTrue(make(OpenApiSigner::class)->verify($payload, self::MERCHANT_SECRET, $payload['sign']), '通知带了正确的签名');

        $queried = $this->api($merchant, 'GET', '/open/v1/order', ['merchant_order_no' => 'M001']);
        $this->assertSame('success', $queried['data']['status']);
        $this->assertNotNull($queried['data']['finished_at']);
        $this->assertSame(['order_pay'], MerchantBalanceLog::where('merchant_id', $merchant->id)->pluck('type')->all());
    }

    public function testFailoverToNextSupplierAndRefundWhenAllFail()
    {
        [$merchant, $product, $mobile, $suppliers] = $this->scenario([
            ['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '98.00', 'result' => 'failed', 'priority' => 1],
            ['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '99.00', 'result' => 'success', 'priority' => 2],
        ]);

        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $order = Order::find($order->id);
        $this->assertSame(Order::STATUS_SUCCESS, $order->status);
        $this->assertSame($suppliers[1]->id, $order->supplier_id, '第一个失败后自动换到第二个');
        $this->assertSame(['failed', 'success'], OrderAttempt::where('order_id', $order->id)->orderBy('id')->pluck('status')->all());

        // 两个都失败：订单失败、退款
        $this->setMockResult($suppliers[1], 'failed');
        $second = $this->placeOrder($merchant, $product, $this->createSegment('cmcc', '广东'));
        $this->process()->submit($second->id);
        $second = Order::find($second->id);
        $this->assertSame(Order::STATUS_FAILED, $second->status);
        $this->assertStringContainsString('所有供应商都充值失败', $second->fail_reason);
        $this->assertSame('100.50', Merchant::find($merchant->id)->balance, '只扣了成功那一单');
        $this->assertSame(['order_pay', 'order_pay', 'order_refund'], MerchantBalanceLog::where('merchant_id', $merchant->id)->orderBy('id')->pluck('type')->all());

        // 已经失败的订单再收到失败结果，不会重复退款
        $this->assertFalse($this->process()->fail($second, [Order::STATUS_PENDING, Order::STATUS_PROCESSING], 'again'));
        $this->assertSame('100.50', Merchant::find($merchant->id)->balance);
    }

    public function testCallFailureMakesManualOnlyOrderAndOnlyRecordsUpstreamResults()
    {
        [$merchant, $product, $mobile, $suppliers] = $this->scenario([
            ['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '98.00', 'result' => 'timeout', 'priority' => 1],
            ['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '99.00', 'result' => 'success', 'priority' => 2],
        ]);

        // 调用异常：上游可能已经建单，不当失败、不换供应商，立即转异常订单人工处理
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $this->assertSame(Order::STATUS_ABNORMAL, Order::find($order->id)->status);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame(OrderAttempt::STATUS_PROCESSING, $attempt->status, '提交本身还在处理中，查单、回调照常');
        $this->assertStringContainsString('模拟超时', $attempt->message);
        $this->assertSame(1, OrderAttempt::where('order_id', $order->id)->count());

        // 再推一次提交也不会换供应商：订单已经是异常
        $this->process()->submit($order->id);
        $this->assertSame(1, OrderAttempt::where('order_id', $order->id)->count());

        $this->assertTrue(Order::find($order->id)->manual_only, '标为人工处理单');

        // 人工处理单：上游说成功也只记到提交上，订单保持异常、不通知商户，商户查单还是处理中
        $this->dispatcher->notifies = [];
        $reply = $this->supplierCallback($suppliers[0], ['attempt_no' => $attempt->attempt_no, 'status' => 'success']);
        $this->assertSame([200, 'ok'], $reply);
        $this->assertSame(OrderAttempt::STATUS_SUCCESS, OrderAttempt::find($attempt->id)->status);
        $this->assertSame(Order::STATUS_ABNORMAL, Order::find($order->id)->status);
        $this->assertSame([], $this->dispatcher->notifies);
        $this->assertSame('processing', make(OrderService::class)->present(Order::find($order->id))['status']);

        // 异常只在后台显示：开放接口查单、回调商户都是 processing，不出现 abnormal
        $queried = $this->api($merchant, 'GET', '/open/v1/order', ['order_no' => $order->order_no]);
        $this->assertSame('OK', $queried['code']);
        $this->assertSame('processing', $queried['data']['status']);
        $this->assertStringNotContainsString('abnormal', json_encode($queried, JSON_UNESCAPED_UNICODE));
        $this->assertSame('abnormal', make(MerchantPortalService::class)->presentOrders([Order::find($order->id)])[0]['status'], '商户后台显示异常');

        // 人工置为成功也不自动通知，商户查单仍是处理中，等手动回调
        $this->assertTrue($this->process()->confirmSuccess(Order::find($order->id), '上游核实已到账'));
        $order = Order::find($order->id);
        $this->assertSame(Order::STATUS_SUCCESS, $order->status);
        $this->assertSame(Order::NOTIFY_NONE, $order->notify_status);
        $this->assertSame([], $this->dispatcher->notifies);
        $this->assertSame('processing', make(OrderService::class)->present($order)['status']);

        // 重复回调、矛盾的回调都不再改变结果
        $this->supplierCallback($suppliers[0], ['attempt_no' => $attempt->attempt_no, 'status' => 'failed']);
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
        $this->assertSame([400, 'invalid'], $this->supplierCallback($suppliers[0], ['attempt_no' => $attempt->attempt_no, 'status' => 'whatever']));
        $this->assertSame(404, $this->client->request('POST', '/notify/supplier/no_such_supplier')->getStatusCode());
    }

    public function testCallFailureThenUpstreamFailureStaysAbnormalForManualRefund()
    {
        [$merchant, $product, $mobile, $suppliers] = $this->scenario([
            ['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '98.00', 'result' => 'timeout', 'priority' => 1],
            ['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '99.00', 'result' => 'success', 'priority' => 2],
        ]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $balance = Merchant::find($merchant->id)->balance;
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        // 上游后来说失败：提交记为失败，但订单保持异常，不自动换供应商、不自动退款，等人工核实
        $this->assertSame([200, 'ok'], $this->supplierCallback($suppliers[0], ['attempt_no' => $attempt->attempt_no, 'status' => 'failed']));
        $this->assertSame(OrderAttempt::STATUS_FAILED, OrderAttempt::find($attempt->id)->status);
        $this->assertSame(Order::STATUS_ABNORMAL, Order::find($order->id)->status);
        $this->process()->submit($order->id);
        $this->assertSame(1, OrderAttempt::where('order_id', $order->id)->count());
        $this->assertSame($balance, Merchant::find($merchant->id)->balance);
    }

    public function testQueryDueAndTimeout()
    {
        [$merchant, $product, $mobile, $suppliers] = $this->scenario([
            ['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '98.00', 'result' => 'processing'],
        ]);

        // 处理中 → 到时间主动查单拿到成功
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame(0, $this->process()->queryDue(), '刚提交不查');
        OrderAttempt::where('order_id', $order->id)->update(['submitted_at' => date('Y-m-d H:i:s', time() - 600)]);
        $this->setMockResult($suppliers[0], 'success');
        $this->assertGreaterThanOrEqual(1, $this->process()->queryDue());
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);

        // 处理中超过 2 小时 → 超时；之后供应商说失败：不换供应商，直接退款并通知商户
        $this->setMockResult($suppliers[0], 'processing');
        $late = $this->placeOrder($merchant, $product, $this->createSegment('cmcc', '广东'));
        $this->process()->submit($late->id);
        Order::where('id', $late->id)->update(['created_at' => date('Y-m-d H:i:s', time() - 3 * 3600)]);
        $this->assertGreaterThanOrEqual(1, $this->process()->markTimeout());
        $this->assertSame(Order::STATUS_TIMEOUT, Order::find($late->id)->status);
        $this->assertSame('1.00', Merchant::find($merchant->id)->balance, '两单都扣了钱');
        $this->dispatcher->notifies = [];
        $attempt = OrderAttempt::where('order_id', $late->id)->first();
        $this->supplierCallback($suppliers[0], ['attempt_no' => $attempt->attempt_no, 'status' => 'failed']);
        $failed = Order::find($late->id);
        $this->assertSame([Order::STATUS_FAILED, Order::NOTIFY_PENDING], [$failed->status, $failed->notify_status]);
        $this->assertStringContainsString('超时后上游返回失败', $failed->fail_reason);
        $this->assertSame(OrderAttempt::STATUS_FAILED, OrderAttempt::find($attempt->id)->status);
        $this->assertCount(1, array_filter($this->dispatcher->submits, fn ($s) => $s['order_id'] === $late->id), '只有下单时推的那一次，超时后不再换供应商');
        $this->assertContains($late->id, array_column($this->dispatcher->notifies, 'order_id'));
        $this->assertSame('100.50', Merchant::find($merchant->id)->balance, '失败的单退款');
    }

    public function testTimeoutOrderSucceedsOnLaterCallback()
    {
        [$merchant, $product, $mobile, $suppliers] = $this->scenario([
            ['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '98.00', 'result' => 'processing'],
        ]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        Order::where('id', $order->id)->update(['created_at' => date('Y-m-d H:i:s', time() - 3 * 3600)]);
        $this->process()->markTimeout();
        $this->assertSame(Order::STATUS_TIMEOUT, Order::find($order->id)->status);
        $this->assertSame('processing', ApplicationContext::getContainer()->get(OrderService::class)->present(Order::find($order->id))['status'], '开放接口里仍是处理中');

        $this->dispatcher->notifies = [];
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->supplierCallback($suppliers[0], ['attempt_no' => $attempt->attempt_no, 'status' => 'success']);
        $done = Order::find($order->id);
        $this->assertSame([Order::STATUS_SUCCESS, '98.00'], [$done->status, $done->cost_price]);
        $this->assertSame([['order_id' => $order->id, 'attempt_no' => 1, 'delay' => 0]], $this->dispatcher->notifies);
    }

    public function testUnsubmittedOrderTimesOutToAbnormal()
    {
        [$merchant, $product, $mobile] = $this->scenario([['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '98.00']]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->assertSame(Order::STATUS_PENDING, Order::find($order->id)->status);
        Order::where('id', $order->id)->update(['created_at' => date('Y-m-d H:i:s', time() - 3 * 3600)]);
        $this->process()->markTimeout();
        $this->assertSame(Order::STATUS_ABNORMAL, Order::find($order->id)->status, '没提交出去的不会有回调，转人工');
    }

    public function testCategoryMobileLimits()
    {
        [$merchant, $product, $mobile] = $this->scenario([['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '98.00', 'result' => 'processing']]);
        $category = $this->createCategory();
        Merchant::where('id', $merchant->id)->update(['balance' => '1000.00']);
        $category->update(['mobile_limit_days' => 1, 'mobile_limit_orders' => 2, 'mobile_limit_processing' => 1]);
        Product::where('id', $product->id)->update(['category_id' => $category->id]);
        $other = $this->createMerchant('200.00');
        $this->openProduct($other, $product, ['cmcc' => '99.50']);
        $recharge = fn (Merchant $m) => $this->api($m, 'POST', '/open/v1/recharge', ['product_code' => $product->code, 'mobile' => $mobile, 'merchant_order_no' => $this->uniq('M')]);

        // 同时充值中最多 1 单
        $first = $this->placeOrder($merchant, $product, $mobile);
        $blocked = $recharge($merchant);
        $this->assertSame('MOBILE_LIMITED', $blocked['code']);
        $this->assertStringContainsString('充值中', $blocked['message']);

        // 当天最多 2 单，失败的也算，所有商户合计
        Order::where('id', $first->id)->update(['status' => Order::STATUS_FAILED]);
        $second = $this->placeOrder($other, $product, $mobile);
        Order::where('id', $second->id)->update(['status' => Order::STATUS_FAILED]);
        $blocked = $recharge($merchant);
        $this->assertSame('MOBILE_LIMITED', $blocked['code']);
        $this->assertStringContainsString('今天已充值 2 单', $blocked['message']);
        $this->assertSame('OK', $this->api($merchant, 'POST', '/open/v1/recharge', ['product_code' => $product->code, 'mobile' => $this->createSegment('cmcc', '广东'), 'merchant_order_no' => $this->uniq('M')])['code'], '别的号码不受影响');

        // 按自然日：昨天的不算
        Order::whereIn('id', [$first->id, $second->id])->update(['created_at' => date('Y-m-d 23:59:59', strtotime('-1 day'))]);
        $this->placeOrder($merchant, $product, $mobile);

        // 没设限制的分类不受影响
        $category->update(['mobile_limit_days' => 0, 'mobile_limit_orders' => 0, 'mobile_limit_processing' => 0]);
        make(CategoryMobileLimitService::class)->forget($category->id);
        $this->assertNotSame('MOBILE_LIMITED', $recharge($merchant)['code']);
    }

    public function testMerchantRateLimit()
    {
        [$merchant, $product] = $this->scenario([['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '98.00']]);
        $merchant->update(['balance' => '1000.00', 'rate_limit_seconds' => 3600, 'rate_limit_orders' => 2]);
        $this->placeOrder($merchant, $product, $this->createSegment('cmcc', '广东'));
        $this->placeOrder($merchant, $product, $this->createSegment('cmcc', '广东'));
        $blocked = $this->api($merchant, 'POST', '/open/v1/recharge', ['product_code' => $product->code, 'mobile' => $this->createSegment('cmcc', '广东'), 'merchant_order_no' => $this->uniq('M')]);
        $this->assertSame('RATE_LIMITED', $blocked['code']);
        $this->assertStringContainsString('每 3600 秒最多 2 单', $blocked['message']);
        $this->assertSame('OK', $this->api($merchant, 'GET', '/open/v1/balance', [])['code'], '查余额不限');

        // 关掉限流
        $merchant->update(['rate_limit_seconds' => 0]);
        $this->placeOrder($merchant, $product, $this->createSegment('cmcc', '广东'));
    }

    public function testStuckOrdersAreResubmitted()
    {
        [$merchant, $product, $mobile] = $this->scenario([['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '98.00']]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->dispatcher->submits = [];

        $this->process()->resubmitStuck();
        $this->assertNotContains($order->id, array_column($this->dispatcher->submits, 'order_id'), '刚下的单不补推');
        Order::where('id', $order->id)->update(['updated_at' => date('Y-m-d H:i:s', time() - 300)]);
        $this->process()->resubmitStuck();
        $this->assertContains($order->id, array_column($this->dispatcher->submits, 'order_id'));
    }

    public function testRejectedOrdersAreNotCreatedOrCharged()
    {
        [$merchant, $product, $mobile] = $this->scenario([['provinces' => ['广东'], 'operators' => ['cmcc', 'ctcc'], 'cost' => '98.00']]);
        $blocked = $this->createSegment('cmcc', '广东');
        $this->blacklistMobiles[] = $blocked;
        MobileBlacklist::create(['mobile' => $blocked]);
        do {
            $unknown = '100' . random_int(10000000, 99999999);
        } while (MobileSegment::where('segment', substr($unknown, 0, 7))->exists());
        $poor = $this->createMerchant('10.00');
        $this->openProduct($poor, $product, ['cmcc' => '99.50']);

        $cases = [
            'INVALID_PARAMS' => [$merchant, ['product_code' => $product->code, 'mobile' => '138', 'merchant_order_no' => 'R1']],
            'PRODUCT_NOT_AVAILABLE' => [$merchant, ['product_code' => 'NO_SUCH', 'mobile' => $mobile, 'merchant_order_no' => 'R2']],
            'BLACKLISTED' => [$merchant, ['product_code' => $product->code, 'mobile' => $blocked, 'merchant_order_no' => 'R3']],
            'UNSUPPORTED_MOBILE' => [$merchant, ['product_code' => $product->code, 'mobile' => $this->createSegment('cmcc', '广东', true), 'merchant_order_no' => 'R4']],
            'PRICE_NOT_SET' => [$merchant, ['product_code' => $product->code, 'mobile' => $this->createSegment('ctcc', '广东'), 'merchant_order_no' => 'R5']],
            'NO_CHANNEL' => [$merchant, ['product_code' => $product->code, 'mobile' => $this->createSegment('cmcc', '湖南'), 'merchant_order_no' => 'R6']],
            'INSUFFICIENT_BALANCE' => [$poor, ['product_code' => $product->code, 'mobile' => $mobile, 'merchant_order_no' => 'R7']],
        ];
        foreach ($cases as $code => [$who, $params]) {
            $this->assertSame($code, $this->api($who, 'POST', '/open/v1/recharge', $params)['code'], $code);
        }
        $this->assertSame('UNSUPPORTED_MOBILE', $this->api($merchant, 'POST', '/open/v1/recharge', ['product_code' => $product->code, 'mobile' => $unknown, 'merchant_order_no' => 'R8'])['code']);

        // 同号码同面值 10 分钟内重复
        $this->assertSame('OK', $this->api($merchant, 'POST', '/open/v1/recharge', ['product_code' => $product->code, 'mobile' => $mobile, 'merchant_order_no' => 'R9'])['code']);
        $this->assertSame('DUPLICATE_RECHARGE', $this->api($merchant, 'POST', '/open/v1/recharge', ['product_code' => $product->code, 'mobile' => $mobile, 'merchant_order_no' => 'R10'])['code']);

        $this->assertSame(1, Order::where('merchant_id', $merchant->id)->count());
        $this->assertSame(0, Order::where('merchant_id', $poor->id)->count());
        $this->assertSame('100.50', Merchant::find($merchant->id)->balance);
        $this->assertSame('10.00', Merchant::find($poor->id)->balance);
    }

    public function testCreditLimitAllowsNegativeBalance()
    {
        [, $product, $mobile] = $this->scenario([['provinces' => ['广东'], 'operators' => ['cmcc'], 'cost' => '98.00']]);
        $merchant = $this->createMerchant('10.00');
        $this->openProduct($merchant, $product, ['cmcc' => '99.50']);
        Merchant::where('id', $merchant->id)->update(['credit_limit' => '100.00']);

        // 余额 10 + 授信 100，够下一单 99.50，扣完余额 -89.50
        $this->assertSame('OK', $this->api($merchant, 'POST', '/open/v1/recharge', ['product_code' => $product->code, 'mobile' => $mobile, 'merchant_order_no' => 'C1'])['code']);
        $this->assertSame('-89.50', Merchant::find($merchant->id)->balance);
        $this->assertSame(['balance' => '-89.50', 'credit_limit' => '100.00', 'available_balance' => '10.50'], $this->api($merchant, 'GET', '/open/v1/balance', [])['data']);

        // 再下一单会超出授信：拒绝，不建单不扣款
        $rejected = $this->api($merchant, 'POST', '/open/v1/recharge', ['product_code' => $product->code, 'mobile' => $this->createSegment('cmcc', '广东'), 'merchant_order_no' => 'C2']);
        $this->assertSame('INSUFFICIENT_BALANCE', $rejected['code']);
        $this->assertSame('可用额度不足：余额 -89.50 元，授信 100.00 元，需要 99.50 元', $rejected['message']);
        $this->assertSame(1, Order::where('merchant_id', $merchant->id)->count());
        $this->assertSame('-89.50', Merchant::find($merchant->id)->balance);
    }

    public function testAuthentication()
    {
        $merchant = $this->createMerchant('5.00');
        $call = fn (array $params) => $this->body($this->client->request('GET', '/open/v1/balance', ['query' => $params]));
        $signed = function (array $override = []) use ($merchant) {
            $params = array_merge(['app_key' => $merchant->app_key, 'timestamp' => (string) time(), 'nonce' => bin2hex(random_bytes(8))], $override);
            $params['sign'] = make(OpenApiSigner::class)->sign($params, self::MERCHANT_SECRET);

            return $params;
        };

        $ok = $signed();
        $this->assertSame(['code' => 'OK', 'message' => '成功', 'data' => ['balance' => '5.00', 'credit_limit' => '0.00', 'available_balance' => '5.00']], $call($ok));
        $this->assertSame('DUPLICATE_NONCE', $call($ok)['code']);
        $this->assertSame('INVALID_SIGN', $call(['sign' => 'bad'] + $signed())['code']);
        $this->assertSame('INVALID_TIMESTAMP', $call($signed(['timestamp' => (string) (time() - 3600)]))['code']);
        $this->assertSame('INVALID_APP_KEY', $call($signed(['app_key' => 'nope']))['code']);
        $this->assertSame('INVALID_PARAMS', $call(['app_key' => $merchant->app_key])['code']);

        $merchant->update(['ip_whitelist' => '203.0.113.7']);
        $this->assertSame('IP_NOT_ALLOWED', $call($signed())['code']);
        $merchant->update(['ip_whitelist' => null, 'status' => 'disabled']);
        $this->assertSame('MERCHANT_DISABLED', $call($signed())['code']);
    }

    public function testNotifyRetriesThenGivesUp()
    {
        [$merchant, $product, $mobile] = $this->scenario([['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '98.00']]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $this->http->responses = [['status' => 500, 'body' => 'error']];
        $this->dispatcher->notifies = [];

        make(MerchantNotifyService::class)->notify($order->id, 1);
        $this->assertSame(Order::NOTIFY_RETRYING, Order::find($order->id)->notify_status);
        $this->assertSame([['order_id' => $order->id, 'attempt_no' => 2, 'delay' => 60]], $this->dispatcher->notifies);

        make(MerchantNotifyService::class)->notify($order->id, 6);
        $this->assertSame(Order::NOTIFY_FAILED, Order::find($order->id)->notify_status);
        $this->assertSame(2, OrderNotifyLog::where('order_id', $order->id)->count());
        $this->assertFalse((bool) OrderNotifyLog::where('order_id', $order->id)->value('success'));
    }

    public function testProductsApiListsOpenedProductsWithPrices()
    {
        [$merchant, $product] = $this->scenario([['provinces' => ['*'], 'operators' => ['cmcc'], 'cost' => '98.00']]);

        $products = $this->api($merchant, 'GET', '/open/v1/products', [])['data'];
        $this->assertSame([['product_code' => $product->code, 'name' => $product->name, 'face_value' => 100, 'prices' => ['cmcc' => '99.50']]], $products);
        $this->assertSame('ORDER_NOT_FOUND', $this->api($merchant, 'GET', '/open/v1/order', ['order_no' => 'nope'])['code']);
    }

    /**
     * 建一个商品（每个供应商一个供应商商品）、一个余额 200 的商户（移动价 99.50）、一个广东移动号码。
     *
     * @param list<array{provinces: list<string>, operators: list<string>, cost: string, result?: string, priority?: int}> $routes
     * @return array{0: Merchant, 1: Product, 2: string, 3: list<Supplier>}
     */
    private function scenario(array $routes): array
    {
        $suppliers = [];
        $bindings = [];
        foreach ($routes as $route) {
            $supplier = $this->createSupplier($route['provinces'], 'active', $route['result'] ?? 'success');
            $suppliers[] = $supplier;
            $bindings[$this->createSupplierProduct($supplier, $route['operators'], 100, $route['cost'])->id] = $route['priority'] ?? 1;
        }
        $product = $this->createProduct($bindings);
        $merchant = $this->createMerchant('200.00');
        $this->openProduct($merchant, $product, ['cmcc' => '99.50']);

        return [$merchant, $product, $this->createSegment('cmcc', '广东'), $suppliers];
    }

    private function placeOrder(Merchant $merchant, Product $product, string $mobile): Order
    {
        $result = $this->api($merchant, 'POST', '/open/v1/recharge', [
            'product_code' => $product->code,
            'mobile' => $mobile,
            'merchant_order_no' => $this->uniq('M'),
        ]);
        $this->assertSame('OK', $result['code'], json_encode($result, JSON_UNESCAPED_UNICODE));

        return Order::where('order_no', $result['data']['order_no'])->first();
    }

    /**
     * 带签名调开放接口。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function api(Merchant $merchant, string $method, string $path, array $params): array
    {
        $params += ['app_key' => $merchant->app_key, 'timestamp' => (string) time(), 'nonce' => bin2hex(random_bytes(8))];
        $params['sign'] = make(OpenApiSigner::class)->sign($params, self::MERCHANT_SECRET);
        $options = $method === 'GET' ? ['query' => $params] : ['json' => $params, 'headers' => ['Content-Type' => 'application/json']];

        return $this->body($this->client->request($method, $path, $options));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function supplierCallback(Supplier $supplier, array $body): array
    {
        $response = $this->client->request('POST', "/notify/supplier/{$supplier->code}", [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => $body,
        ]);

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    private function process(): OrderProcessService
    {
        return ApplicationContext::getContainer()->get(OrderProcessService::class);
    }

    private function body($response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
