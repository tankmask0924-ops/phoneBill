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
use App\Supplier\Driver\ChongyidaDriver;
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
 * 充易达驱动：JSON + 大写 md5 签名、账户信息、商品列表、下单、查单（docs/充易达.md 最后一节）。
 *
 * @internal
 * @coversNothing
 */
class ChongyidaDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const CONFIG = [
        'api_url' => 'https://cyd.test/',
        'user_no' => 'A000012',
        'secret_key' => 'cyd_key',
        'notify_url' => 'https://pb.test/notify/supplier/cyd',
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

    public function testSignMatchesDocumentExamples()
    {
        // 文档「签名规则说明」和「账户信息」的示例
        $this->assertSame(
            '18BFE2813CDBC4D52914FF8F4CC7D0E2',
            ChongyidaDriver::sign(['userNo' => 'A000012', 'timestamp' => 1764249192274, 'amount' => '48.5'], '6ef7979f4c6b7faca9e8f0d4cc3055d5')
        );
        $this->assertSame(
            '537D310CCA9DFB8DF21AE6B0D004FB3A',
            ChongyidaDriver::sign(['sign' => 'ignored', 'timestamp' => 1764250566713, 'userNo' => 'A000012'], '6ef7979f4c6b7faca9e8f0d4cc3055d5')
        );
        // 空值不参与、不做 URL 编码
        $this->assertSame(
            strtoupper(md5('a=1&notifyUrl=http://x.com/n?a=1&secretKey=xx')),
            ChongyidaDriver::sign(['notifyUrl' => 'http://x.com/n?a=1', 'a' => '1', 'b' => ''], 'xx')
        );
    }

    public function testRechargeSendsSignedJsonAndMapsResults()
    {
        $this->respond(['code' => 0, 'message' => '提单成功！', 'data' => [
            'userNo' => 'A000012', 'orderNo' => 'C26010917201555300184E7', 'clientOrderNo' => 'A0001', 'totalAmount' => '46.20', 'timestamp' => 1767950415743, 'sign' => 'X',
        ]]);
        $result = $this->driver()->recharge($this->request());

        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertSame('C26010917201555300184E7', $result->supplierOrderNo);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_JSON', $sent['method']);
        $this->assertSame('https://cyd.test/open/v1/checkout', $sent['url']);
        $payload = $sent['payload'];
        $this->assertSame('A0001', $payload['clientOrderNo']);
        $this->assertSame('68', $payload['productId']);
        $this->assertSame('13800138000', $payload['phone']);
        $this->assertSame(self::CONFIG['notify_url'], $payload['notifyUrl']);
        $this->assertSame('A000012', $payload['userNo']);
        $this->assertIsInt($payload['timestamp']);
        $this->assertSame(13, strlen((string) $payload['timestamp']));
        $this->assertSame(ChongyidaDriver::sign($payload, 'cyd_key'), $payload['sign']);
        $this->assertArrayNotHasKey('secretKey', $payload);
        $this->assertArrayNotHasKey('secret_key', $payload);

        // code 非 0（含订单号重复、表外的码）：失败换下一家
        foreach ([[3001, '订单号重复，请勿重复提交'], [1002, '签名不正确'], [8888, '']] as [$code, $message]) {
            $this->respond(['code' => $code, 'message' => $message, 'data' => null]);
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Failed, $result->status, "code {$code}");
            $this->assertFalse($result->callFailed);
        }
        $this->assertStringContainsString('[8888]', $result->message);

        // 没有响应体、返回无法解析：没有拿到返回值，转异常人工处理
        $this->http->responses = [['status' => 200, 'body' => '']];
        $result = $this->driver()->recharge($this->request());
        $this->assertTrue($result->callFailed);
        $this->assertStringContainsString('没有拿到上游返回', $result->message);
        $this->http->responses = [['status' => 502, 'body' => '<html>Bad Gateway</html>']];
        $this->assertTrue($this->driver()->recharge($this->request())->callFailed);
    }

    public function testRechargeOmitsEmptyNotifyUrl()
    {
        $this->respond(['code' => 0, 'message' => 'ok', 'data' => ['orderNo' => 'C1']]);
        $config = ['notify_url' => ''] + self::CONFIG;
        $this->driver()->recharge(new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', '68', $config));

        $payload = $this->http->requests[0]['payload'];
        $this->assertArrayNotHasKey('notifyUrl', $payload);
        $this->assertSame(ChongyidaDriver::sign($payload, 'cyd_key'), $payload['sign']);
    }

    public function testQueryMapsOrderStatus()
    {
        $statuses = ['SUCCESS' => RechargeStatus::Success, 'FAILED' => RechargeStatus::Failed, 'PROCESSING' => RechargeStatus::Processing, 'CREATED' => RechargeStatus::Processing];
        foreach ($statuses as $status => $mapped) {
            $this->respond(['code' => 0, 'message' => 'success', 'data' => [
                ['clientOrderNo' => 'OTHER', 'orderNo' => 'C0', 'orderStatus' => 'SUCCESS'],
                ['clientOrderNo' => 'A0001', 'orderNo' => 'C26010917235685200215B5', 'orderStatus' => $status, 'totalAmount' => 46.2],
            ]]);
            $result = $this->driver()->query($this->request());
            $this->assertSame($mapped, $result->status, $status);
            $this->assertSame('C26010917235685200215B5', $result->supplierOrderNo);
        }
        $sent = $this->http->requests[0];
        $this->assertSame('https://cyd.test/open/v1/orders', $sent['url']);
        $this->assertSame('A0001', $sent['payload']['clientOrderNo']);
        $this->assertArrayNotHasKey('orderNo', $sent['payload']);
        $this->assertSame(ChongyidaDriver::sign($sent['payload'], 'cyd_key'), $sent['payload']['sign']);

        // 2003 查不到：只记录，处理中
        $this->respond(['code' => 2003, 'message' => '未查询到数据', 'data' => null]);
        $result = $this->driver()->query($this->request('C9'));
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertSame('C9', $result->supplierOrderNo);
        $this->assertStringContainsString('查不到这笔订单', $result->message);

        // 出错、空列表、单号对不上、没有状态、无法解析：处理中
        foreach ([
            ['code' => 9000, 'message' => '服务错误', 'data' => null],
            ['code' => 0, 'message' => 'success', 'data' => []],
            ['code' => 0, 'message' => 'success', 'data' => [['clientOrderNo' => 'OTHER', 'orderStatus' => 'SUCCESS']]],
            ['code' => 0, 'message' => 'success', 'data' => [['clientOrderNo' => 'A0001']]],
        ] as $body) {
            $this->respond($body);
            $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status, json_encode($body));
        }
        $this->http->responses = [['status' => 502, 'body' => 'Bad Gateway']];
        $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status);
    }

    public function testUpstreamProductsAndBalance()
    {
        $this->respond(['code' => 0, 'message' => 'success', 'data' => [
            ['productId' => '1', 'name' => '三网50元', 'faceValue' => 50, 'unit' => '元', 'price' => 46.2, 'carrier' => ['1', '2', '3'], 'carrierName' => ['移动', '联通', '电信'], 'category' => '话费', 'type' => '快充'],
            ['productId' => 2, 'name' => '电信100', 'faceValue' => 100, 'price' => '97.50', 'carrier' => [3]],
            ['name' => '没有编号的跳过'],
        ]]);
        $products = array_map(static fn ($p) => $p->toArray(), $this->driver()->upstreamProducts(self::CONFIG));
        $sent = $this->http->requests[0];
        $this->assertSame('https://cyd.test/open/v1/catalog', $sent['url']);
        $this->assertSame(['userNo', 'timestamp', 'sign'], array_keys($sent['payload']));
        $this->assertCount(2, $products);
        $this->assertSame(
            ['code' => '1', 'name' => '三网50元', 'price' => '46.2', 'face_value' => 50, 'operators' => ['cmcc', 'cucc', 'ctcc'], 'on_sale' => true, 'note' => '话费 · 快充'],
            $products[0]
        );
        $this->assertSame(['code' => '2', 'name' => '电信100', 'price' => '97.50', 'face_value' => 100, 'operators' => ['ctcc'], 'on_sale' => true, 'note' => null], $products[1]);

        $this->respond(['code' => 1002, 'message' => '签名不正确', 'data' => null]);
        try {
            $this->driver()->upstreamProducts(self::CONFIG);
            $this->fail('上游出错应抛异常');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('[1002] 签名不正确', $e->getMessage());
        }

        $this->respond(['code' => 0, 'message' => 'success', 'data' => [
            'userNo' => 'A000012', 'username' => 'pb', 'level' => 'V1', 'status' => 'Activated', 'currency' => 'CNY', 'balance' => 1000.5, 'totalRecharge' => 2000, 'totalConsumed' => 999,
        ]]);
        $this->assertSame('1000.5', $this->driver()->balance(self::CONFIG));
        $this->assertSame('https://cyd.test/open/v1/accounts', $this->http->requests[2]['url']);

        $this->respond(['code' => 1001, 'message' => '用户不存在', 'data' => null]);
        $this->expectException(RuntimeException::class);
        $this->driver()->balance(self::CONFIG);
    }

    public function testSubmitThroughOrderFlowAndRejectCallbacks()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['code' => 0, 'message' => '提单成功！', 'data' => ['orderNo' => 'C000001']]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame('C000001', $attempt->supplier_order_no);
        $this->assertSame($attempt->attempt_no, $this->http->requests[0]['payload']['clientOrderNo']);

        // 不接回调：一律不采用，订单不变，结果靠查单
        $response = $this->client->request('POST', "/notify/supplier/{$supplier->code}", ['json' => ['clientOrderNo' => $attempt->attempt_no, 'orderStatus' => 'SUCCESS']]);
        $this->assertSame([400, 'fail'], [$response->getStatusCode(), (string) $response->getBody()]);
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
    }

    /**
     * @return array{0: Merchant, 1: Product, 2: string, 3: Supplier}
     */
    private function scenario(): array
    {
        $supplier = $this->createSupplier(['广东']);
        $supplier->update(['driver' => 'chongyida', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
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

    private function request(?string $supplierOrderNo = null): RechargeRequest
    {
        return new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', '68', self::CONFIG, $supplierOrderNo);
    }

    private function driver(): ChongyidaDriver
    {
        return ApplicationContext::getContainer()->get(ChongyidaDriver::class);
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
