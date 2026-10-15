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
use App\Supplier\Driver\YingyetingDriver;
use App\Supplier\RechargeRequest;
use App\Supplier\RechargeStatus;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\HttpMessage\Server\Request as Psr7Request;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Testing\Client;
use HyperfTest\Cases\Admin\CreatesCatalog;
use HyperfTest\HttpTestCase;
use HyperfTest\Support\FakeHttpClient;
use HyperfTest\Support\FakeOrderDispatcher;

use function Hyperf\Support\make;

/**
 * 营业厅驱动：请求头签名、结算价、结果判定、按上游订单号查单、回调验签（docs/营业厅.md）。
 *
 * @internal
 * @coversNothing
 */
class YingyetingDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const CONFIG = [
        'api_url' => 'https://yyt.test/',
        'api_key' => 'ak_7f3c9b21e8d5460a',
        'secret' => 'abcdef1234567890',
        'notify_url' => 'https://pb.test/notify/supplier/yyt',
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

    public function testSignFollowsDocumentExamples()
    {
        // 文档 4.2、4.3 的示例
        $this->assertSame('179982F6FD7B6CC0B3CEE22C8D610CCF', YingyetingDriver::sign([
            'upstream_order_no' => 'UP20260727000001', 'phone' => '13800138000', 'face_value' => '50', 'price' => '48.50', 'timestamp' => '1769493600',
        ], 'abcdef1234567890'));
        $this->assertSame('132025CDB10B2452638F3FF969BF5394', YingyetingDriver::sign(['timestamp' => '1769493600'], 'abcdef1234567890'));
        // 空值、数组不参与
        $this->assertSame(
            YingyetingDriver::sign(['timestamp' => '1769493600'], 'abcdef1234567890'),
            YingyetingDriver::sign(['timestamp' => '1769493600', 'city' => '', 'province' => null, 'x' => ['a'], 'sign' => 'X'], 'abcdef1234567890')
        );
    }

    public function testRechargeSendsSignedFormWithCostPrice()
    {
        $this->respond(['code' => 0, 'message' => '下单成功', 'data' => ['order_no' => 'R260727120000482913', 'upstream_order_no' => 'A0001', 'status' => 0, 'status_name' => '待接单']]);
        $result = $this->driver()->recharge($this->request());

        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertSame('R260727120000482913', $result->supplierOrderNo);
        $sent = $this->http->requests[0];
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('https://yyt.test/openapi/order', $sent['url']);
        parse_str($sent['payload']['body'], $form);
        $this->assertSame(['upstream_order_no' => 'A0001', 'phone' => '13800138000', 'face_value' => '100', 'price' => '98.80', 'notify_url' => self::CONFIG['notify_url']], $form);
        $headers = $sent['payload']['headers'];
        $this->assertSame('ak_7f3c9b21e8d5460a', $headers['X-Api-Key']);
        $this->assertSame('application/x-www-form-urlencoded', $headers['Content-Type']);
        $this->assertSame(YingyetingDriver::sign($form + ['timestamp' => $headers['X-Timestamp']], 'abcdef1234567890'), $headers['X-Sign']);

        // 下单返回里已经是终态：直接采用
        $this->respond(['code' => 0, 'message' => '下单成功', 'data' => ['order_no' => 'R1', 'status' => 5]]);
        $this->assertSame(RechargeStatus::Failed, $this->driver()->recharge($this->request())->status);

        // 没有成本价：不发请求，直接失败
        $before = count($this->http->requests);
        $result = $this->driver()->recharge(new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', 'ext', self::CONFIG));
        $this->assertSame(RechargeStatus::Failed, $result->status);
        $this->assertCount($before, $this->http->requests);
    }

    public function testRechargeMapsErrorCodes()
    {
        // 文档列出的拒单错误码：失败换下一家
        foreach (['10001', '10002', '50100', '50101', '50102', '50103', '60001', '40103', '40104', '40105', '40106', '40107', '40108', '40109'] as $code) {
            $this->respond(['code' => (int) $code, 'message' => '拒单' . $code, 'data' => null]);
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Failed, $result->status, $code);
            $this->assertFalse($result->callFailed, $code);
            $this->assertStringContainsString("[{$code}] 拒单{$code}", $result->message);
        }
        $this->respond(['code' => 40109, 'message' => '', 'data' => null]);
        $this->assertStringContainsString('[40109] 平台余额不足', $this->driver()->recharge($this->request())->message);

        // 上游有返回、不是 0：500、10000、表外错误码也都判失败
        foreach ([['code' => 500, 'message' => '服务器内部错误'], ['code' => 10000, 'message' => '操作失败'], ['code' => 12345, 'message' => 'x']] as $body) {
            $this->respond($body);
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Failed, $result->status, json_encode($body));
            $this->assertFalse($result->callFailed, json_encode($body));
            $this->assertStringContainsString("[{$body['code']}] {$body['message']}", $result->message);
        }

        // HTTP 出错、返回无法解析：没有拿到返回值，转异常，带上原始报文
        $this->http->responses = [['status' => 502, 'body' => 'Bad Gateway']];
        $result = $this->driver()->recharge($this->request());
        $this->assertTrue($result->callFailed);
        $this->assertStringContainsString('Bad Gateway', $result->response);
        $this->assertStringContainsString('A0001', $result->request);
    }

    public function testQueryUsesUpstreamOrderNo()
    {
        $statuses = [3 => RechargeStatus::Success, 5 => RechargeStatus::Failed, 6 => RechargeStatus::Failed, 7 => RechargeStatus::Failed, 0 => RechargeStatus::Processing, 1 => RechargeStatus::Processing];
        foreach ($statuses as $status => $mapped) {
            $this->respond(['code' => 0, 'message' => '成功', 'data' => ['order_no' => 'R1', 'upstream_order_no' => 'A0001', 'status' => $status]]);
            $this->assertSame($mapped, $this->driver()->query($this->request('R1'))->status, (string) $status);
        }
        $sent = $this->http->requests[0];
        $this->assertSame('GET', $sent['method']);
        $this->assertSame('https://yyt.test/openapi/order/R1', $sent['url']);
        $this->assertSame('', $sent['payload']['body']);
        $this->assertSame(YingyetingDriver::sign(['timestamp' => $sent['payload']['headers']['X-Timestamp']], 'abcdef1234567890'), $sent['payload']['headers']['X-Sign']);
        $this->respond(['code' => 0, 'message' => '成功', 'data' => ['order_no' => 'R1', 'upstream_order_no' => 'A0001', 'status' => 7]]);
        $this->assertSame('上游已冲正（成功后被撤销）', $this->driver()->query($this->request('R1'))->message);

        // 订单不存在、单号对不上、没有状态：处理中
        foreach ([
            ['code' => 40100, 'message' => '订单不存在', 'data' => null],
            ['code' => 0, 'message' => '成功', 'data' => ['upstream_order_no' => 'OTHER', 'status' => 3]],
            ['code' => 0, 'message' => '成功', 'data' => ['upstream_order_no' => 'A0001']],
        ] as $body) {
            $this->respond($body);
            $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request('R1'))->status, json_encode($body));
        }

        // 没有上游订单号：不发请求，处理中
        $before = count($this->http->requests);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertStringContainsString('没有上游订单号', $result->message);
        $this->assertCount($before, $this->http->requests);
        $this->assertNull($this->driver()->balance(self::CONFIG));
    }

    public function testCallbackVerifiesDocumentExample()
    {
        // 文档 6.2 的回调原文：数字按报文原样参与验签
        $raw = '{"order_no":"R260727120000482913","upstream_order_no":"UP20260727000001","phone":"13800138000","face_value":50,"price":48.5,"status":3,"status_name":"已完成","finish_time":1769493900,"timestamp":1769493900,"sign":"9895651CCA5C06027E622DB970EFA2CD"}';
        $parsed = $this->driver()->parseCallback(new Psr7Request('POST', '/notify', ['Content-Type' => 'application/json'], new SwooleStream($raw)), self::CONFIG);
        $this->assertTrue($parsed->verified);
        $this->assertSame('success', $parsed->reply);
        $this->assertSame('UP20260727000001', $parsed->attemptNo);
        $this->assertSame('R260727120000482913', $parsed->supplierOrderNo);
        $this->assertSame(RechargeStatus::Success, $parsed->result->status);

        // 改了状态、换了密钥：验签不过
        $tampered = str_replace('"status":3', '"status":6', $raw);
        $this->assertFalse($this->driver()->parseCallback(new Psr7Request('POST', '/notify', [], new SwooleStream($tampered)), self::CONFIG)->verified);
        $this->assertFalse($this->driver()->parseCallback(new Psr7Request('POST', '/notify', [], new SwooleStream($raw)), ['secret' => 'wrong'] + self::CONFIG)->verified);
    }

    public function testCallbackCompletesAndFailsOrders()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['code' => 0, 'message' => '下单成功', 'data' => ['order_no' => 'R000001', 'status' => 0]]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame('R000001', $attempt->supplier_order_no);
        parse_str($this->http->requests[0]['payload']['body'], $form);
        $this->assertSame('98.80', $form['price'], '结算价是供应商商品的成本价');

        $forged = $this->callbackParams($attempt->attempt_no, 3);
        $forged['sign'] = str_repeat('0', 32);
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $forged));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 3)));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);

        // 退单：失败，换不到下一家就退款
        $this->respond(['code' => 0, 'message' => '下单成功', 'data' => ['order_no' => 'R000002', 'status' => 0]]);
        $second = $this->placeOrder($merchant, $product, substr($mobile, 0, 7) . '5678');
        $this->process()->submit($second->id);
        $attempt = OrderAttempt::where('order_id', $second->id)->first();
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 6)));
        $this->assertSame(OrderAttempt::STATUS_FAILED, OrderAttempt::find($attempt->id)->status);
        $this->process()->submit($second->id);
        $this->assertSame(Order::STATUS_FAILED, Order::find($second->id)->status);
    }

    public function testNoReplyMarksOrderAbnormal()
    {
        [$merchant, $product, $mobile] = $this->scenario();

        $this->http->responses = [['status' => 502, 'body' => 'Bad Gateway']];
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);

        $order = Order::find($order->id);
        $this->assertSame(Order::STATUS_ABNORMAL, $order->status);
        $this->assertTrue((bool) $order->manual_only);
        $this->assertSame(1, OrderAttempt::where('order_id', $order->id)->count(), '不换下一家');
    }

    /**
     * @return array{0: Merchant, 1: Product, 2: string, 3: Supplier}
     */
    private function scenario(): array
    {
        $supplier = $this->createSupplier(['广东']);
        $supplier->update(['driver' => 'yingyeting', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
        $supplierProduct = $this->createSupplierProduct($supplier, ['cmcc'], 100, '98.80');
        $product = $this->createProduct([$supplierProduct->id => 1]);
        $merchant = $this->createMerchant('300.00');
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
     * 照文档 6.2 的字段造一份回调（值都用字符串，和 JSON 数字原样签名的结果一致）。
     *
     * @return array<string, string>
     */
    private function callbackParams(string $attemptNo, int $status): array
    {
        $params = [
            'order_no' => 'R000001',
            'upstream_order_no' => $attemptNo,
            'phone' => '13800138000',
            'face_value' => '100',
            'price' => '98.8',
            'status' => (string) $status,
            'status_name' => $status === 3 ? '已完成' : '已退单',
            'finish_time' => (string) time(),
            'timestamp' => (string) time(),
        ];
        $params['sign'] = YingyetingDriver::sign($params, self::CONFIG['secret']);

        return $params;
    }

    /**
     * @param array<string, string> $params
     * @return array{0: int, 1: string}
     */
    private function supplierCallback(Supplier $supplier, array $params): array
    {
        $response = $this->client->request('POST', "/notify/supplier/{$supplier->code}", ['json' => $params, 'headers' => ['Content-Type' => 'application/json']]);

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    private function request(?string $supplierOrderNo = null): RechargeRequest
    {
        return new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', 'ext', self::CONFIG, $supplierOrderNo, '98.80');
    }

    private function driver(): YingyetingDriver
    {
        return ApplicationContext::getContainer()->get(YingyetingDriver::class);
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
