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

namespace HyperfTest\Cases\Supplier;

use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\Product;
use App\Model\Supplier;
use App\Network\HttpClient;
use App\Security\Encrypter;
use App\Security\OpenApiSigner;
use App\Service\Order\OrderDispatcher;
use App\Service\Order\OrderProcessService;
use App\Service\Order\SupplierCallbackService;
use App\Supplier\Driver\TurboDriver;
use App\Supplier\RechargeRequest;
use App\Supplier\RechargeStatus;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\Cases\Admin\CreatesAdmins;
use HyperfTest\Cases\Admin\CreatesCatalog;
use HyperfTest\HttpTestCase;
use HyperfTest\Support\FakeHttpClient;
use HyperfTest\Support\FakeOrderDispatcher;
use RuntimeException;

use function Hyperf\Support\make;

/**
 * Turbo TOPUP 驱动：k=v 拼接的大写 md5 签名、下单、查单、余额、商品列表、回调查单确认（docs/Turbo-TOPUP-API.md 最后一节）。
 *
 * @internal
 * @coversNothing
 */
class TurboDriverTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

    private const CONFIG = [
        'api_url' => 'https://turbo.test/',
        'access_key' => 'c50dc8a657d3bfa7a76786983a71dc0c',
        'secret_key' => 'turbo_secret',
        'notify_url' => 'https://pb.test/notify/supplier/tb',
    ];

    private FakeHttpClient $http;

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->http = new FakeHttpClient();
        ApplicationContext::getContainer()->set(OrderDispatcher::class, new FakeOrderDispatcher());
        ApplicationContext::getContainer()->set(HttpClient::class, $this->http);
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testSignMatchesDocument()
    {
        // 文档第二节的签名串：键名升序、值不编码、末尾 &secretKey=；sign 不参与
        $this->assertSame(strtoupper(md5(
            'accessKey=ak&externalOrderNo=TR900000001&notificationUrl=https://www/baidu.com&phone=17305318794&productId=P000019&secretKey=sk'
        )), TurboDriver::sign([
            'productId' => 'P000019', 'phone' => '17305318794', 'externalOrderNo' => 'TR900000001',
            'notificationUrl' => 'https://www/baidu.com', 'accessKey' => 'ak', 'sign' => 'x',
        ], 'sk'));
    }

    public function testRechargeSendsSignedJsonAndAcceptsSuccess()
    {
        $this->respond($this->ok(['orderNo' => 'TD2025042310200558', 'orderStatus' => 'Processing', 'rechargeStatus' => 0, 'externalOrderNo' => 'A0001']));
        $result = $this->driver()->recharge($this->request());

        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertFalse($result->callFailed);
        $this->assertSame('TD2025042310200558', $result->supplierOrderNo);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_JSON', $sent['method']);
        $this->assertSame('https://turbo.test/integration/v1/checkout', $sent['url']);
        $this->assertSame([
            'accessKey' => 'c50dc8a657d3bfa7a76786983a71dc0c',
            'productId' => 'P000019',
            'phone' => '13800138000',
            'externalOrderNo' => 'A0001',
            'notificationUrl' => 'https://pb.test/notify/supplier/tb',
            'sign' => strtoupper(md5('accessKey=c50dc8a657d3bfa7a76786983a71dc0c&externalOrderNo=A0001&notificationUrl=https://pb.test/notify/supplier/tb&phone=13800138000&productId=P000019&secretKey=turbo_secret')),
        ], $sent['payload']);

        // 没有回调地址：不发空的 notificationUrl，也不参与签名
        $this->http->requests = [];
        $this->driver()->recharge(new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', 'P000019', ['notify_url' => ''] + self::CONFIG));
        $payload = $this->http->requests[0]['payload'];
        $this->assertArrayNotHasKey('notificationUrl', $payload);
        $this->assertSame(strtoupper(md5('accessKey=c50dc8a657d3bfa7a76786983a71dc0c&externalOrderNo=A0001&phone=13800138000&productId=P000019&secretKey=turbo_secret')), $payload['sign']);

        // 下单时上游已给出终态
        $this->respond($this->ok(['orderNo' => 'TD1', 'orderStatus' => 'Failed', 'rechargeStatus' => 2]));
        $this->assertSame(RechargeStatus::Failed, $this->driver()->recharge($this->request())->status);
    }

    public function testRechargeFailsOnErrorReply()
    {
        foreach ([200, 400, 401, 500] as $http) {
            $this->respond(['status' => 'error', 'message' => 'REQUEST_EXPIRED', 'data' => null, 'error' => ['code' => 'REQUEST_EXPIRED', 'details' => '请求过期']], $http);
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Failed, $result->status, "HTTP {$http}");
            $this->assertFalse($result->callFailed, "HTTP {$http}");
            $this->assertStringContainsString('[REQUEST_EXPIRED] 请求过期', $result->message);
        }

        // 没有响应体、返回无法解析、没有 status、HTTP 出错：没有拿到返回值，转异常人工处理
        foreach ([
            ['status' => 200, 'body' => ''],
            ['status' => 200, 'body' => 'ok'],
            ['status' => 200, 'body' => '{"message":"没有 status"}'],
            ['status' => 502, 'body' => '<html>Bad Gateway</html>'],
        ] as $response) {
            $this->http->responses = [$response];
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Processing, $result->status, $response['body']);
            $this->assertTrue($result->callFailed, $response['body']);
            $this->assertStringContainsString('没有拿到上游返回', $result->message);
        }
    }

    public function testQueryMapsOrderStatus()
    {
        $this->respond($this->ok($this->orderData('Succeeded', 1)));
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Success, $result->status);
        $this->assertSame('TD2025042310200558', $result->supplierOrderNo);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_JSON', $sent['method']);
        $this->assertSame('https://turbo.test/integration/v1/orders', $sent['url']);
        $this->assertSame([
            'accessKey' => 'c50dc8a657d3bfa7a76786983a71dc0c',
            'orderNo' => 'A0001',
            'sign' => strtoupper(md5('accessKey=c50dc8a657d3bfa7a76786983a71dc0c&orderNo=A0001&secretKey=turbo_secret')),
        ], $sent['payload']);

        foreach (['Failed' => 2, 'Cancel' => -1] as $status => $recharge) {
            $this->respond($this->ok($this->orderData($status, $recharge)));
            $this->assertSame(RechargeStatus::Failed, $this->driver()->query($this->request())->status, $status);
        }

        foreach (['Created' => 4, 'Processing' => 0, 'Unknown' => 0] as $status => $recharge) {
            $this->respond($this->ok($this->orderData($status, $recharge)));
            $result = $this->driver()->query($this->request());
            $this->assertSame(RechargeStatus::Processing, $result->status, $status);
            $this->assertSame('TD2025042310200558', $result->supplierOrderNo);
        }

        // 部分成功：不判成功也不判失败
        foreach (['Succeeded', 'Failed'] as $status) {
            $this->respond($this->ok($this->orderData($status, 3)));
            $result = $this->driver()->query($this->request());
            $this->assertSame(RechargeStatus::Processing, $result->status, $status);
            $this->assertStringContainsString('部分成功', $result->message);
        }

        // 查无此单（实测报文）：判失败
        foreach ([
            ['status' => 'error', 'message' => 'INVALID_PARAMETER', 'data' => null, 'error' => ['code' => 'INVALID_PARAMETER', 'details' => 'Order not found.']],
            ['status' => 'error', 'message' => 'Order not found', 'data' => null, 'error' => null],
        ] as $body) {
            $this->respond($body);
            $result = $this->driver()->query($this->request());
            $this->assertSame(RechargeStatus::Failed, $result->status, json_encode($body));
            $this->assertStringContainsString('上游无此订单', $result->message);
        }

        // 上游其他报错、没有状态、订单号对不上、没拿到返回：不处理订单
        foreach ([
            ['status' => 'error', 'message' => 'INVALID_PARAMETER', 'data' => null, 'error' => ['code' => 'INVALID_PARAMETER', 'details' => 'Invalid sign']],
            ['status' => 'error', 'message' => 'REQUEST_EXPIRED', 'data' => null, 'error' => ['code' => 'REQUEST_EXPIRED', 'details' => '请求过期']],
            $this->ok(['externalOrderNo' => 'A0001']),
            $this->ok(['externalOrderNo' => 'OTHER'] + $this->orderData('Succeeded', 1)),
            ['message' => '没有 status'],
        ] as $body) {
            $this->respond($body);
            $result = $this->driver()->query($this->request());
            $this->assertSame(RechargeStatus::Processing, $result->status, json_encode($body));
            $this->assertFalse($result->callFailed);
            $this->assertNotNull($result->message);
        }
        $this->http->responses = [['status' => 502, 'body' => 'Bad Gateway']];
        $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status);
    }

    public function testBalance()
    {
        $this->respond($this->ok(['account' => 'cyd', 'fullName' => '小李子', 'currency' => 'CNY', 'balance' => '6300.65', 'isActive' => true]));
        $this->assertSame('6300.65', $this->driver()->balance(self::CONFIG));
        $sent = $this->http->requests[0];
        $this->assertSame('https://turbo.test/integration/v1/accounts', $sent['url']);
        $this->assertSame([
            'accessKey' => 'c50dc8a657d3bfa7a76786983a71dc0c',
            'sign' => strtoupper(md5('accessKey=c50dc8a657d3bfa7a76786983a71dc0c&secretKey=turbo_secret')),
        ], $sent['payload']);

        foreach ([['status' => 'error', 'data' => null, 'error' => ['code' => 'UNAUTHORIZED']], $this->ok([]), ['message' => 'x']] as $body) {
            $this->respond($body);
            try {
                $this->driver()->balance(self::CONFIG);
                $this->fail('查询失败应抛异常：' . json_encode($body));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('查询余额失败', $e->getMessage());
            }
        }
    }

    public function testUpstreamProducts()
    {
        $this->respond($this->ok(['result' => [
            [
                'productId' => 'P000013', 'productName' => '三网快充100',
                'brandId' => ['_id' => 'x', 'brandId' => '1', 'brandName' => '话费'],
                'cateId' => [['_id' => 'y', 'cateId' => '1', 'cateName' => '❤️三网快充']],
                'premiumPercentage' => 0, 'pType' => '快充', 'faceValue' => 100, 'status' => true, 'price' => 95.5,
            ],
            ['productId' => 'P000014', 'productName' => '移动慢充50', 'brandId' => ['brandName' => '中国移动'], 'faceValue' => 50, 'status' => false, 'price' => 47],
            ['productName' => '没有 productId'],
        ], 'total' => 3]));
        $products = $this->driver()->upstreamProducts(self::CONFIG);

        $this->assertSame('https://turbo.test/integration/v1/catalog', $this->http->requests[0]['url']);
        $this->assertCount(2, $products);
        $this->assertSame([
            'code' => 'P000013',
            'name' => '三网快充100',
            'price' => '95.5',
            'face_value' => 100,
            'operators' => [],
            'on_sale' => true,
            'note' => '❤️三网快充 / 快充',
        ], $products[0]->toArray());
        // brandName 带运营商名才认运营商；status=false 下架
        $this->assertSame(['cmcc'], $products[1]->operators);
        $this->assertFalse($products[1]->onSale);
        $this->assertNull($products[1]->note);

        $this->respond(['status' => 'error', 'data' => null, 'error' => ['code' => 'UNAUTHORIZED']]);
        $this->expectException(RuntimeException::class);
        $this->driver()->upstreamProducts(self::CONFIG);
    }

    public function testCallbackIsConfirmedByQuery()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond($this->ok(['orderNo' => 'TD2025042310200558', 'orderStatus' => 'Processing', 'rechargeStatus' => 0]));
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame($attempt->attempt_no, $this->http->requests[0]['payload']['externalOrderNo']);
        $this->assertSame('ext', $this->http->requests[0]['payload']['productId']);

        // 没有订单号：拒绝
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, ['orderStatus' => 'Succeeded']));

        // 查单没拿到明确结果（查不到、单号对不上、HTTP 5xx）：回 fail，订单不变；查无此单也不凭回调判失败
        $this->respond(['status' => 'error', 'data' => null, 'error' => ['code' => 'INVALID_PARAMETER', 'details' => 'Order not found.']]);
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'Failed', 2)));
        $this->respond(['status' => 'error', 'data' => null, 'error' => ['code' => 'REQUEST_EXPIRED']]);
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'Succeeded', 1)));
        $this->respond($this->ok(['externalOrderNo' => 'OTHER'] + $this->orderData('Succeeded', 1)));
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'Succeeded', 1)));
        $this->http->responses = [['status' => 502, 'body' => '<html>Bad Gateway</html>']];
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'Failed', 2)));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame(OrderAttempt::STATUS_PROCESSING, OrderAttempt::find($attempt->id)->status);

        // 回调说成功，查单还在充值中：以查单为准，订单不变
        $this->http->requests = [];
        $this->respond($this->ok(['externalOrderNo' => $attempt->attempt_no] + $this->orderData('Processing', 0)));
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'Succeeded', 1)));
        $this->assertSame('https://turbo.test/integration/v1/orders', $this->http->requests[0]['url']);
        $this->assertSame($attempt->attempt_no, $this->http->requests[0]['payload']['orderNo']);
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 查单成功：订单成功
        $this->respond($this->ok(['externalOrderNo' => $attempt->attempt_no] + $this->orderData('Succeeded', 1)));
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'Succeeded', 1)));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
        $this->assertSame('TD2025042310200558', OrderAttempt::find($attempt->id)->supplier_order_no);
    }

    public function testUnconfirmedCallbackIsRecordedAndConfirmedLater()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        // 下单超时：转人工处理单，提交上照样记下发出去的请求
        $this->http->responses = [['exception' => 'cURL error 28: Operation timed out after 10001 milliseconds']];
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame([Order::STATUS_ABNORMAL, true], [Order::find($order->id)->status, (bool) Order::find($order->id)->manual_only]);
        $this->assertSame($attempt->attempt_no, json_decode((string) $attempt->request, true)['externalOrderNo']);
        $this->assertStringStartsWith('调用异常：cURL error 28', $attempt->message);

        // 回调来了，确认查单超时：回 fail，原因和请求记到提交的查单记录上，30 秒后重新确认
        $this->http->responses = [['exception' => 'cURL error 28: Operation timed out after 5001 milliseconds']];
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'Succeeded', 1)));
        $attempt = OrderAttempt::find($attempt->id);
        $this->assertSame('processing', $attempt->query_status);
        $this->assertStringStartsWith('回调后确认查单：调用异常：cURL error 28', $attempt->query_message);
        $this->assertSame($attempt->attempt_no, json_decode((string) $attempt->query_request, true)['orderNo']);
        $this->assertNotNull($attempt->last_queried_at);
        $this->assertSame(0, $attempt->query_http_status, '没拿到响应：接口不通');
        $confirms = $this->dispatcher()->callbackConfirms;
        $this->assertSame([[$supplier->code, 1, 30]], array_map(static fn ($c) => [$c['supplier'], $c['retry'], $c['delay']], $confirms));
        $this->assertSame($attempt->attempt_no, json_decode($confirms[0]['body'], true)['externalOrderNo']);
        $this->assertSame(Order::STATUS_ABNORMAL, Order::find($order->id)->status);

        // 第 1 次重新确认：上游还查不到单（回调触发的不凭查无此单判失败），记下上游原样返回，60 秒后再确认
        $notFound = (string) json_encode(['status' => 'error', 'data' => null, 'error' => ['code' => 'INVALID_PARAMETER', 'details' => 'Order not found.']]);
        $this->http->responses = [['status' => 400, 'body' => $notFound]];
        $this->replay($confirms[0]);
        $attempt = OrderAttempt::find($attempt->id);
        $this->assertSame('回调后确认查单：上游无此订单：[INVALID_PARAMETER] Order not found.', $attempt->query_message);
        $this->assertSame($notFound, $attempt->query_response);
        $this->assertSame(400, $attempt->query_http_status, '上游有返回但 HTTP 报错');
        $this->assertSame([OrderAttempt::STATUS_PROCESSING, Order::STATUS_ABNORMAL], [$attempt->status, Order::find($order->id)->status]);
        $confirms = $this->dispatcher()->callbackConfirms;
        $this->assertSame([2, 60], [$confirms[1]['retry'], $confirms[1]['delay']]);

        // 第 2 次确认到成功：和正常回调一样，人工处理单交回正常流程，改成功并通知商户
        $this->respond($this->ok(['externalOrderNo' => $attempt->attempt_no] + $this->orderData('Succeeded', 1)));
        $this->replay($confirms[1]);
        $order = Order::find($order->id);
        $this->assertSame([Order::STATUS_SUCCESS, false], [$order->status, (bool) $order->manual_only]);
        $this->assertSame([OrderAttempt::STATUS_SUCCESS, 200], [OrderAttempt::find($attempt->id)->status, OrderAttempt::find($attempt->id)->query_http_status]);
        $this->assertSame([$order->id], array_column($this->dispatcher()->notifies, 'order_id'));
        $this->assertCount(2, $this->dispatcher()->callbackConfirms, '确认到结果就不再重试');

        // 提交已经有结果：再来确认不了的回调也不记、不重试
        $this->http->responses = [['exception' => 'timeout']];
        $this->replay($confirms[1]);
        $this->assertCount(2, $this->dispatcher()->callbackConfirms);
        $this->assertSame('success', OrderAttempt::find($attempt->id)->query_status);
    }

    public function testAdminQueryNotFoundFailsAbnormalOrderAndNotifies()
    {
        [$merchant, $product, $mobile] = $this->scenario();
        $this->http->responses = [['exception' => 'cURL error 28: Operation timed out']];
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame([Order::STATUS_ABNORMAL, true], [Order::find($order->id)->status, (bool) Order::find($order->id)->manual_only]);
        $notFound = ['status' => 'error', 'data' => null, 'error' => ['code' => 'INVALID_PARAMETER', 'details' => 'Order not found.']];

        // 定时查单查不到：人工处理单只记录（提交记成失败，订单不动）
        $this->respond($notFound, 400);
        $this->process()->queryAttempt(OrderAttempt::find($attempt->id));
        $this->assertSame([Order::STATUS_ABNORMAL, OrderAttempt::STATUS_FAILED], [Order::find($order->id)->status, OrderAttempt::find($attempt->id)->status]);
        $this->assertSame([], $this->dispatcher()->notifies);

        // 人工点查单，上游明确查不到：直接失败退款并通知商户，不再换供应商
        $submitted = count($this->dispatcher()->submits);
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.manage']));
        $queried = $this->body($this->jsonRequest('POST', "/admin/orders/{$order->id}/query", $token));
        $this->assertSame([['attempt_no' => $attempt->attempt_no, 'status' => 'failed', 'http_status' => 400, 'not_found' => true, 'applied' => true]], array_map(
            static fn (array $r) => array_diff_key($r, ['message' => 1]),
            $queried['query_results'],
        ), '提交早被定时查单记成失败，这次置失败也算处理了');
        $this->assertSame([Order::STATUS_FAILED, false], [$queried['status'], $queried['manual_only']]);
        $order = Order::find($order->id);
        $this->assertSame([Order::STATUS_FAILED, false], [$order->status, (bool) $order->manual_only]);
        $this->assertStringStartsWith('人工查单上游查不到这笔订单：上游无此订单', $order->fail_reason);
        $this->assertSame(OrderAttempt::STATUS_FAILED, OrderAttempt::find($attempt->id)->status);
        $this->assertSame(1, MerchantBalanceLog::where('order_id', $order->id)->where('type', MerchantBalanceLog::TYPE_ORDER_REFUND)->count());
        $dispatcher = $this->dispatcher();
        $this->assertSame([$order->id], array_column($dispatcher->notifies, 'order_id'));
        $this->assertCount($submitted, $dispatcher->submits, '不换供应商');

        // 不是异常的订单人工查到查无此单：照原规则（Turbo 判失败换下一家）
        $this->respond($this->ok(['orderNo' => 'TD2', 'orderStatus' => 'Processing', 'rechargeStatus' => 0]));
        $other = $this->placeOrder($merchant, $product, $mobile = $this->createSegment('cmcc', '广东'));
        $this->process()->submit($other->id);
        $this->respond($notFound, 400);
        $submitted = count($dispatcher->submits);
        $this->process()->queryAttempt(OrderAttempt::where('order_id', $other->id)->first(), true);
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($other->id)->status);
        $this->assertSame([$other->id], array_column(array_slice($dispatcher->submits, $submitted), 'order_id'));
    }

    public function testUnconfirmedCallbackStopsRetryingAfterThreeTimes()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();
        $this->respond($this->ok(['orderNo' => 'TD2025042310200558', 'orderStatus' => 'Processing', 'rechargeStatus' => 0]));
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        // 已经重新确认了 3 次还是不行：只记录，不再推任务，留给定时查单
        $this->http->responses = [['status' => 503, 'body' => 'Service Unavailable']];
        $body = (string) json_encode($this->callbackParams($attempt->attempt_no, 'Succeeded', 1));
        make(SupplierCallbackService::class)->replay($supplier->code, $body, 'application/json', [], count(SupplierCallbackService::RETRY_DELAYS));
        $this->assertSame([], $this->dispatcher()->callbackConfirms);
        $this->assertSame('回调后确认查单：查单未拿到明确结果：HTTP 503 Service Unavailable', OrderAttempt::find($attempt->id)->query_message);
        $this->assertSame(503, OrderAttempt::find($attempt->id)->query_http_status);

        // 定时查单接口超时：查单记录照样带着发出的请求
        $this->http->responses = [['exception' => 'cURL error 7: Failed to connect']];
        $this->process()->queryAttempt(OrderAttempt::find($attempt->id));
        $attempt = OrderAttempt::find($attempt->id);
        $this->assertSame('调用异常：cURL error 7: Failed to connect', $attempt->query_message);
        $this->assertSame($attempt->attempt_no, json_decode((string) $attempt->query_request, true)['orderNo']);
        $this->assertNull($attempt->query_response);
        $this->assertSame(0, $attempt->query_http_status);
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 接口正常：HTTP 200
        $this->respond($this->ok(['externalOrderNo' => $attempt->attempt_no] + $this->orderData('Processing', 0)));
        $result = $this->process()->queryAttempt(OrderAttempt::find($attempt->id));
        $this->assertSame([200, 200], [$result->httpStatus, OrderAttempt::find($attempt->id)->query_http_status]);
    }

    public function testCallbackFailureFailsOrder()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond($this->ok(['orderNo' => 'TD2025042310200558', 'orderStatus' => 'Processing', 'rechargeStatus' => 0]));
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        $this->respond($this->ok(['externalOrderNo' => $attempt->attempt_no] + $this->orderData('Failed', 2)));
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'Failed', 2)));
        $this->assertSame(OrderAttempt::STATUS_FAILED, OrderAttempt::find($attempt->id)->status);

        $this->process()->submit($order->id);
        $this->assertSame(Order::STATUS_FAILED, Order::find($order->id)->status);
        $this->assertSame('200.00', Merchant::find($merchant->id)->balance);
    }

    public function testQueryNotFoundFailsAttempt()
    {
        [$merchant, $product, $mobile] = $this->scenario();

        $this->respond($this->ok(['orderNo' => 'TD2025042310200558', 'orderStatus' => 'Processing', 'rechargeStatus' => 0]));
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        // 定时查单查无此单：提交判失败，换下一家（只有一家时失败退款）
        $this->respond(['status' => 'error', 'message' => 'INVALID_PARAMETER', 'data' => null, 'error' => ['code' => 'INVALID_PARAMETER', 'details' => 'Order not found.']]);
        $this->process()->queryAttempt($attempt);
        $this->assertSame(OrderAttempt::STATUS_FAILED, OrderAttempt::find($attempt->id)->status);
        $this->assertSame(RechargeStatus::Failed->value, OrderAttempt::find($attempt->id)->query_status);

        $this->process()->submit($order->id);
        $this->assertSame(Order::STATUS_FAILED, Order::find($order->id)->status);
        $this->assertSame('200.00', Merchant::find($merchant->id)->balance);
    }

    public function testNoReplyMarksOrderAbnormal()
    {
        [$merchant, $product, $mobile] = $this->scenario();

        $this->http->responses = [['status' => 504, 'body' => 'Gateway Timeout']];
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);

        $order = Order::find($order->id);
        $this->assertSame(Order::STATUS_ABNORMAL, $order->status);
        $this->assertTrue((bool) $order->manual_only);
        $this->assertSame('100.50', Merchant::find($merchant->id)->balance, '不退款');
        $this->assertSame(1, OrderAttempt::where('order_id', $order->id)->count(), '不换下一家');
    }

    /**
     * @return array{0: Merchant, 1: Product, 2: string, 3: Supplier}
     */
    private function scenario(): array
    {
        $supplier = $this->createSupplier(['广东']);
        $supplier->update(['driver' => 'turbo', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
        $supplierProduct = $this->createSupplierProduct($supplier, ['cmcc'], 100, '98.80');
        $product = $this->createProduct([$supplierProduct->id => 1]);
        $merchant = $this->createMerchant('200.00');
        $this->openProduct($merchant, $product, ['cmcc' => '99.50']);

        return [$merchant, $product, $this->createSegment('cmcc', '广东'), $supplier];
    }

    private function placeOrder(Merchant $merchant, Product $product, string $mobile): Order
    {
        $params = ['product_code' => $product->code, 'mobile' => $mobile, 'merchant_order_no' => $this->uniq('M')];
        $params += ['app_key' => $merchant->app_key, 'timestamp' => (string) time(), 'nonce' => bin2hex(random_bytes(8))];
        $params['sign'] = make(OpenApiSigner::class)->sign($params, self::MERCHANT_SECRET);
        $response = $this->client->request('POST', '/open/v1/recharge', ['json' => $params, 'headers' => ['Content-Type' => 'application/json']]);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('OK', $body['code'] ?? null, (string) $response->getBody());

        return Order::where('order_no', $body['data']['order_no'])->first();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function ok(array $data): array
    {
        return ['status' => 'success', 'message' => 'Request succeeded', 'data' => $data, 'error' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderData(string $orderStatus, int $rechargeStatus): array
    {
        return [
            'orderNo' => 'TD2025042310200558',
            'externalOrderNo' => 'A0001',
            'phone' => '13800138000',
            'totalAmount' => '95.00',
            'orderType' => '话费',
            'rechargeStatus' => $rechargeStatus,
            'orderStatus' => $orderStatus,
            'certificate' => '',
        ];
    }

    /**
     * 照文档字段造一份回调（文档里回调不带签名）。
     *
     * @return array<string, mixed>
     */
    private function callbackParams(string $attemptNo, string $orderStatus, int $rechargeStatus): array
    {
        return [
            'orderNo' => 'TD2025042310200558',
            'isp' => '移动',
            'area' => '-',
            'city' => '-',
            'orderType' => '话费',
            'userId' => 'u1',
            'notificationUrl' => self::CONFIG['notify_url'],
            'ip' => '0.0.0.0',
            'phone' => '13800138000',
            'productId' => 'ext',
            'certificate' => '',
            'totalAmount' => '95.00',
            'rechargeStatus' => $rechargeStatus,
            'externalOrderNo' => $attemptNo,
            'orderStatus' => $orderStatus,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: int, 1: string}
     */
    private function supplierCallback(Supplier $supplier, array $params): array
    {
        $response = $this->client->request('POST', "/notify/supplier/{$supplier->code}", ['json' => $params, 'headers' => ['Content-Type' => 'application/json']]);

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    private function request(): RechargeRequest
    {
        return new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', 'P000019', self::CONFIG);
    }

    private function driver(): TurboDriver
    {
        return ApplicationContext::getContainer()->get(TurboDriver::class);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(array $body, int $status = 200): void
    {
        $this->http->responses = [['status' => $status, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE)]];
    }

    private function dispatcher(): FakeOrderDispatcher
    {
        return ApplicationContext::getContainer()->get(OrderDispatcher::class);
    }

    /**
     * 执行一个推出去的重新确认任务。
     *
     * @param array{supplier: string, body: string, content_type: string, query: array<string, mixed>, retry: int, delay: int} $confirm
     */
    private function replay(array $confirm): void
    {
        make(SupplierCallbackService::class)->replay($confirm['supplier'], $confirm['body'], $confirm['content_type'], $confirm['query'], $confirm['retry']);
    }

    private function process(): OrderProcessService
    {
        return ApplicationContext::getContainer()->get(OrderProcessService::class);
    }
}
