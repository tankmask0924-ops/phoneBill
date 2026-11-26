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
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\Product;
use App\Model\Supplier;
use App\Network\HttpClient;
use App\Security\Encrypter;
use App\Security\OpenApiSigner;
use App\Service\Order\OrderDispatcher;
use App\Service\Order\OrderProcessService;
use App\Supplier\Driver\QylmDriver;
use App\Supplier\RechargeRequest;
use App\Supplier\RechargeStatus;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\Cases\Admin\CreatesCatalog;
use HyperfTest\HttpTestCase;
use HyperfTest\Support\FakeHttpClient;
use HyperfTest\Support\FakeOrderDispatcher;
use RuntimeException;

use function Hyperf\Support\make;

/**
 * QYLM 驱动：请求头 HMAC-SHA256 签名、商品列表、下单、查单、余额、回调（docs/QYLM开放接口对接文档-代理端下单-V1.0.md 最后一节）。
 *
 * @internal
 * @coversNothing
 */
class QylmDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const CONFIG = [
        'api_url' => 'http://qylm.test/api/open/v1/',
        'agent_id' => '1001',
        'api_key' => 'qylm_key',
        'notify_url' => 'https://pb.test/notify/supplier/qylm',
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
        parent::tearDown();
    }

    public function testSignFollowsDocumentAlgorithm()
    {
        // 6 段用 \n 连接，METHOD 大写，hmac-sha256 小写十六进制
        $this->assertSame(
            hash_hmac('sha256', "1001\nGET\n/api/open/v1/products?page=1&size=20\n1762521600\nabc\n", 'k'),
            QylmDriver::sign('1001', 'get', '/api/open/v1/products?page=1&size=20', '1762521600', 'abc', '', 'k')
        );
    }

    public function testCallbackSignFollowsDocumentAlgorithm()
    {
        // timestamp 和 order_no 用 \n 连接，hmac-sha256 小写十六进制
        $this->assertSame(hash_hmac('sha256', "1762500672\nAP1", 'k'), QylmDriver::callbackSign('1762500672', 'AP1', 'k'));
    }

    public function testRechargeSendsSignedJsonAndMapsResults()
    {
        $this->respond(['code' => 0, 'msg' => 'ok', 'data' => ['order_no' => 'AP202611072043121A2B3C', 'amount' => '9.9000', 'balance_after' => '113.5500', 'status' => 'pending', 'status_label' => '等待处理']], 201);
        $result = $this->driver()->recharge($this->request());

        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertSame('AP202611072043121A2B3C', $result->supplierOrderNo);
        $sent = $this->http->requests[0];
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('http://qylm.test/api/open/v1/orders', $sent['url']);
        $this->assertSame('{"product_id":12,"params":"13800138000","notify_url":"https://pb.test/notify/supplier/qylm"}', $sent['payload']['body']);
        $this->assertSame('{"product_id":12,"params":"13800138000","notify_url":"https://pb.test/notify/supplier/qylm"}', $result->request);
        $this->assertSignedHeaders($sent, 'POST', '/api/open/v1/orders');
        $this->assertSame('application/json', $sent['payload']['headers']['Content-Type']);

        // 配了交易密码：随单发送，但不记进原始报文
        $this->respond(['code' => 0, 'msg' => 'ok', 'data' => ['order_no' => 'AP2']], 201);
        $result = $this->driver()->recharge($this->request(null, ['trade_password' => 'tp123']));
        $this->assertSame('{"product_id":12,"params":"13800138000","notify_url":"https://pb.test/notify/supplier/qylm","trade_password":"tp123"}', $this->http->requests[1]['payload']['body']);
        $this->assertStringNotContainsString('tp123', (string) $result->request);
        $this->assertSignedHeaders($this->http->requests[1], 'POST', '/api/open/v1/orders');

        // 没有回调地址时不传 notify_url
        $this->respond(['code' => 0, 'msg' => 'ok', 'data' => ['order_no' => 'AP3']], 201);
        $this->driver()->recharge($this->request(null, ['notify_url' => '']));
        $this->assertSame('{"product_id":12,"params":"13800138000"}', $this->http->requests[2]['payload']['body']);

        // code 非 0（签名、商品、参数、余额不足、表外的码）：失败换下一家
        foreach ([[401, '签名错误'], [404, '商品不存在或不可购买'], [422, '余额不足，请先充值'], [500, '']] as [$code, $msg]) {
            $this->respond(['code' => $code, 'msg' => $msg, 'data' => []], $code);
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Failed, $result->status, "code {$code}");
            $this->assertFalse($result->callFailed);
        }
        $this->assertStringContainsString('[500]', $result->message);

        // 说成功却没给订单号：查不了单，转异常人工处理
        $this->respond(['code' => 0, 'msg' => 'ok', 'data' => ['status' => 'pending']], 201);
        $result = $this->driver()->recharge($this->request());
        $this->assertTrue($result->callFailed);
        $this->assertStringContainsString('没有返回订单号', $result->message);

        // 没有响应体、返回无法解析：没有拿到返回值，转异常人工处理
        $this->http->responses = [['status' => 200, 'body' => '']];
        $result = $this->driver()->recharge($this->request());
        $this->assertTrue($result->callFailed);
        $this->assertStringContainsString('没有拿到上游返回', $result->message);
        $this->http->responses = [['status' => 502, 'body' => '<html>Bad Gateway</html>']];
        $this->assertTrue($this->driver()->recharge($this->request())->callFailed);
    }

    public function testQueryMapsOrderStatus()
    {
        $statuses = [
            'success' => RechargeStatus::Success,
            'failed' => RechargeStatus::Failed,
            'cancelled' => RechargeStatus::Failed,
            'refunded' => RechargeStatus::Failed,
            'unpaid' => RechargeStatus::Failed,
            'pending' => RechargeStatus::Processing,
            'processing' => RechargeStatus::Processing,
            'awaiting' => RechargeStatus::Processing,
            'partial_refund' => RechargeStatus::Processing,
        ];
        foreach ($statuses as $status => $mapped) {
            $this->respond(['code' => 0, 'msg' => 'ok', 'data' => ['order_no' => 'AP1', 'status' => $status, 'message' => $status === 'failed' ? '号码已停机' : '']]);
            $result = $this->driver()->query($this->request('AP1'));
            $this->assertSame($mapped, $result->status, $status);
            $this->assertSame('AP1', $result->supplierOrderNo);
        }
        $this->assertStringContainsString('部分退款', $result->message);
        $sent = $this->http->requests[0];
        $this->assertSame('GET', $sent['method']);
        $this->assertSame('http://qylm.test/api/open/v1/orders/AP1', $sent['url']);
        $this->assertSame('', $sent['payload']['body']);
        $this->assertArrayNotHasKey('Content-Type', $sent['payload']['headers']);
        $this->assertSignedHeaders($sent, 'GET', '/api/open/v1/orders/AP1');

        // 失败原因取 message
        $this->respond(['code' => 0, 'msg' => 'ok', 'data' => ['order_no' => 'AP1', 'status' => 'failed', 'message' => '号码已停机']]);
        $this->assertSame('号码已停机（failed）', $this->driver()->query($this->request('AP1'))->message);

        // 404 订单不存在：只记录，处理中
        $this->respond(['code' => 404, 'msg' => '订单不存在', 'data' => []], 404);
        $result = $this->driver()->query($this->request('AP1'));
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertStringContainsString('查不到这笔订单', $result->message);

        // 出错、单号对不上、没有状态、无法解析：处理中
        foreach ([
            ['code' => 401, 'msg' => '签名错误', 'data' => []],
            ['code' => 0, 'msg' => 'ok', 'data' => ['order_no' => 'OTHER', 'status' => 'success']],
            ['code' => 0, 'msg' => 'ok', 'data' => ['order_no' => 'AP1']],
        ] as $body) {
            $this->respond($body);
            $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request('AP1'))->status, json_encode($body));
        }
        $this->http->responses = [['status' => 502, 'body' => 'Bad Gateway']];
        $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request('AP1'))->status);

        // 没有上游订单号（或含非法字符）：不发请求，处理中
        $count = count($this->http->requests);
        $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status);
        $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request('AP1/../x'))->status);
        $this->assertCount($count, $this->http->requests);
    }

    public function testUpstreamProductsAndBalance()
    {
        $item = static fn (int $id, array $extra = []) => $extra + [
            'id' => $id, 'name' => "商品{$id}", 'category_id' => 3, 'category_name' => '话费充值', 'price' => '9.90',
            'product_type' => 'recharge', 'order_template' => '手机号码', 'purchase_limit' => 0, 'sales' => 1, 'stock' => null, 'avg_duration' => 15,
        ];
        // 第一页满 100 条，翻到第二页取完
        $this->http->responses = [
            ['status' => 200, 'body' => json_encode(['code' => 0, 'msg' => 'ok', 'data' => ['items' => array_map($item, range(1, 100)), 'total' => 102, 'page' => 1, 'size' => 100]])],
            ['status' => 200, 'body' => json_encode(['code' => 0, 'msg' => 'ok', 'data' => ['items' => [
                $item(101, ['name' => '广东移动话费 100 元', 'price' => '98.50', 'stock' => 9999]),
                $item(102, ['name' => '联通电信 50 元', 'stock' => 0, 'order_template' => '', 'category_name' => '']),
            ], 'total' => 102, 'page' => 2, 'size' => 100]], JSON_UNESCAPED_UNICODE)],
        ];
        $products = array_map(static fn ($p) => $p->toArray(), $this->driver()->upstreamProducts(self::CONFIG));

        $this->assertCount(102, $products);
        $this->assertCount(2, $this->http->requests);
        $this->assertSame('http://qylm.test/api/open/v1/products?page=1&size=100', $this->http->requests[0]['url']);
        $this->assertSignedHeaders($this->http->requests[1], 'GET', '/api/open/v1/products?page=2&size=100');
        $this->assertSame(
            ['code' => '101', 'name' => '广东移动话费 100 元', 'price' => '98.50', 'face_value' => null, 'operators' => ['cmcc'], 'on_sale' => true, 'note' => '话费充值 / 下单参数：手机号码'],
            $products[100]
        );
        $this->assertSame(['cucc', 'ctcc'], $products[101]['operators']);
        $this->assertFalse($products[101]['on_sale'], 'stock 0 算没货');
        $this->assertNull($products[101]['note']);
        $this->assertTrue($products[0]['on_sale'], 'stock null 不限库存');
        $this->assertSame([], $products[0]['operators']);

        $this->respond(['code' => 401, 'msg' => '签名错误', 'data' => []], 401);
        try {
            $this->driver()->upstreamProducts(self::CONFIG);
            $this->fail('上游出错应抛异常');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('[401] 签名错误', $e->getMessage());
        }

        $this->respond(['code' => 0, 'msg' => 'ok', 'data' => ['agent_id' => 1001, 'name' => '测试代理', 'level' => 'VIP1', 'balance' => '123.4500', 'frozen' => '0.0000', 'consumption' => '100.0000', 'today_consumption' => '10.0000']]);
        $this->assertSame('123.4500', $this->driver()->balance(self::CONFIG));
        $this->assertSame('http://qylm.test/api/open/v1/account', $this->http->requests[3]['url']);
        $this->assertSignedHeaders($this->http->requests[3], 'GET', '/api/open/v1/account');

        $this->respond(['code' => 401, 'msg' => '签名错误', 'data' => []], 401);
        $this->expectException(RuntimeException::class);
        $this->driver()->balance(self::CONFIG);
    }

    public function testOrderFlowAppliesSignedCallbacks()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['code' => 0, 'msg' => 'ok', 'data' => ['order_no' => 'AP000001', 'status' => 'pending']], 201);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame('AP000001', $attempt->supplier_order_no);
        $this->assertSame(
            ['product_id' => 12, 'params' => $mobile, 'notify_url' => self::CONFIG['notify_url']],
            json_decode($this->http->requests[0]['payload']['body'], true)
        );

        // 签名不对、timestamp 超过 300 秒、缺字段：回 fail（HTTP 400，上游会重试），订单不变
        foreach ([
            ['sign' => str_repeat('0', 64)],
            ['timestamp' => time() - 301],
            ['timestamp' => time() + 301],
            ['order_no' => ''],
        ] as $override) {
            $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams('AP000001', 'success', $override)), json_encode($override));
        }
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 表外的状态：回 success，不处理订单
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams('AP000001', 'partial_refund')));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 回调成功（签名大写也认），按上游 order_no 找回提交
        $params = $this->callbackParams('AP000001', 'success');
        $params['sign'] = strtoupper($params['sign']);
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $params));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
        $this->assertCount(1, $this->http->requests, '回调不再查单');
    }

    public function testCallbackFailureAndQueryFallback()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        // 回调失败：换下一家，只有这一家时订单失败退款
        $this->respond(['code' => 0, 'msg' => 'ok', 'data' => ['order_no' => 'AP000002', 'status' => 'pending']], 201);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams('AP000002', 'cancelled', ['message' => '已取消'])));
        $attempt = OrderAttempt::find($attempt->id);
        $this->assertSame(OrderAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('已取消（cancelled）', $attempt->message);
        $this->process()->submit($order->id);
        $this->assertSame(Order::STATUS_FAILED, Order::find($order->id)->status);

        // 没收到回调：定时查单兜底
        $this->respond(['code' => 0, 'msg' => 'ok', 'data' => ['order_no' => 'AP000003', 'status' => 'pending']], 201);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->respond(['code' => 0, 'msg' => 'ok', 'data' => ['order_no' => 'AP000003', 'status' => 'success']]);
        $this->process()->queryAttempt(OrderAttempt::find($attempt->id));
        $this->assertSame('http://qylm.test/api/open/v1/orders/AP000003', $this->http->requests[count($this->http->requests) - 1]['url']);
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
    }

    /**
     * 文档第 07 章的回调报文，按接口 KEY 签名；$override 覆盖签名前的字段（sign 直接覆盖签名）。
     *
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function callbackParams(string $orderNo, string $status, array $override = []): array
    {
        $sign = $override['sign'] ?? null;
        unset($override['sign']);
        $params = $override + [
            'event' => 'order.completed',
            'order_no' => $orderNo,
            'product_id' => 12,
            'product_name' => '全国移动话费 10 元',
            'params' => '13800138000',
            'amount' => '9.90',
            'status' => $status,
            'message' => $status === 'success' ? '充值成功' : '',
            'created_at' => '2026-11-24 12:00:00',
            'completed_at' => '2026-11-24 12:01:12',
            'timestamp' => time(),
        ];
        $params['sign'] = $sign ?? QylmDriver::callbackSign((string) $params['timestamp'], (string) $params['order_no'], 'qylm_key');

        return $params;
    }

    /**
     * 回调是 POST JSON，带 X-Event 头。
     *
     * @param array<string, mixed> $params
     * @return array{0: int, 1: string}
     */
    private function supplierCallback(Supplier $supplier, array $params): array
    {
        $response = $this->client->request('POST', "/notify/supplier/{$supplier->code}", [
            'json' => $params,
            'headers' => ['Content-Type' => 'application/json', 'X-Event' => 'order.completed'],
        ]);

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    /**
     * 请求头带齐 X-Agent-Id / X-Timestamp / X-Nonce，X-Sign 按实际发送的 URI 和请求体算。
     *
     * @param array{method: string, url: string, payload: array<string, mixed>} $sent
     */
    private function assertSignedHeaders(array $sent, string $method, string $uri): void
    {
        $headers = $sent['payload']['headers'];
        $this->assertSame('1001', $headers['X-Agent-Id']);
        $this->assertMatchesRegularExpression('/^\d{10}$/', $headers['X-Timestamp']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $headers['X-Nonce']);
        $this->assertSame(
            QylmDriver::sign('1001', $method, $uri, $headers['X-Timestamp'], $headers['X-Nonce'], $sent['payload']['body'], 'qylm_key'),
            $headers['X-Sign']
        );
        $this->assertStringNotContainsString('qylm_key', json_encode($sent));
    }

    /**
     * @return array{0: Merchant, 1: Product, 2: string, 3: Supplier}
     */
    private function scenario(): array
    {
        $supplier = $this->createSupplier(['广东']);
        $supplier->update(['driver' => 'qylm', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
        $supplierProduct = $this->createSupplierProduct($supplier, ['cmcc'], 100, '98.80');
        $supplierProduct->update(['external_code' => '12']);
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
     * @param array<string, string> $config
     */
    private function request(?string $supplierOrderNo = null, array $config = []): RechargeRequest
    {
        return new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', '12', $config + self::CONFIG, $supplierOrderNo);
    }

    private function driver(): QylmDriver
    {
        return ApplicationContext::getContainer()->get(QylmDriver::class);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(array $body, int $status = 200): void
    {
        $this->http->responses = [['status' => $status, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE)]];
    }

    private function process(): OrderProcessService
    {
        return ApplicationContext::getContainer()->get(OrderProcessService::class);
    }
}
