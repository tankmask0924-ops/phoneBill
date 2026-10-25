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
use App\Supplier\Driver\LinxianDriver;
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
 * 邻显科技驱动：表单 + http_build_query 拼接的大写 md5 签名、下单、查单、余额、商品列表、回调（docs/邻显科技.md 最后一节）。
 *
 * @internal
 * @coversNothing
 */
class LinxianDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const CONFIG = [
        'api_url' => 'http://lx.test/',
        'appid' => 'WBHFKYRPK0',
        'secret' => 'lx_secret',
        'notify_url' => 'https://pb.test/notify/supplier/lx',
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
        $secret = 'LcI7xSAoE84fPpo6xJ2Y3TqK7yD7Bxh0';
        // 文档 PHP 示例
        $this->assertSame('9BFF8AB5DE616B012958ADBB9A802A05', LinxianDriver::sign(['appid' => 'WBHFKYRPK0'], $secret));
        // 文档国漫示例：值做 URL 编码；sign 不参与
        $this->assertSame('D7875CDB565DAB2F99A30CEBB1FDCABA', LinxianDriver::sign([
            'product_id' => '1000', 'mobile' => '13800000000_中国台湾,中国香港,中国澳门', 'appid' => 'WBHFKYRPK0', 'sign' => 'x',
        ], $secret));
        // 空值也参与
        $this->assertSame(strtoupper(md5('a=&b=1&secret=k')), LinxianDriver::sign(['b' => '1', 'a' => ''], 'k'));
    }

    public function testRechargeSendsSignedFormAndAcceptsZero()
    {
        $this->respond(['message' => '手机号码：13800138000，订单已提交', 'data' => ['balance' => 973.9, 'price' => 98.5, 'order_no' => 'WB2026001', 'out_order_no' => 'A0001'], 'code' => 0]);
        $result = $this->driver()->recharge($this->request());

        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertFalse($result->callFailed);
        $this->assertSame('WB2026001', $result->supplierOrderNo);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_FORM', $sent['method']);
        $this->assertSame('http://lx.test/api/recharge', $sent['url']);
        $this->assertSame([
            'appid' => 'WBHFKYRPK0',
            'out_order_no' => 'A0001',
            'mobile' => '13800138000',
            'product_id' => '24',
            'sign' => strtoupper(md5('appid=WBHFKYRPK0&mobile=13800138000&out_order_no=A0001&product_id=24&secret=lx_secret')),
        ], $sent['payload']);
    }

    public function testRechargeFailsOnAnyOtherCode()
    {
        foreach ([1, 500, 40001, '-1'] as $code) {
            $this->respond(['message' => '产品不存在', 'data' => [], 'code' => $code]);
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Failed, $result->status, "code {$code}");
            $this->assertFalse($result->callFailed, "code {$code}");
            $this->assertStringContainsString("[{$code}] 产品不存在", $result->message);
        }

        // 没有响应体、返回无法解析、没有 code、HTTP 出错：没有拿到返回值，转异常人工处理
        foreach ([
            ['status' => 200, 'body' => ''],
            ['status' => 200, 'body' => 'success'],
            ['status' => 200, 'body' => '{"message":"没有 code"}'],
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
        $this->respond(['message' => '获取成功', 'data' => $this->orderData('FINISH'), 'code' => 0]);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Success, $result->status);
        $this->assertSame('WB2026001', $result->supplierOrderNo);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_FORM', $sent['method']);
        $this->assertSame('http://lx.test/api/order', $sent['url']);
        $this->assertSame([
            'appid' => 'WBHFKYRPK0',
            'out_order_no' => 'A0001',
            'sign' => strtoupper(md5('appid=WBHFKYRPK0&out_order_no=A0001&secret=lx_secret')),
        ], $sent['payload']);

        $this->respond(['message' => '获取成功', 'data' => $this->orderData('FAILED'), 'code' => 0]);
        $this->assertSame(RechargeStatus::Failed, $this->driver()->query($this->request())->status);

        foreach (['PASS', 'RUNNING', 'UNKNOWN'] as $status) {
            $this->respond(['message' => '获取成功', 'data' => $this->orderData($status), 'code' => 0]);
            $result = $this->driver()->query($this->request());
            $this->assertSame(RechargeStatus::Processing, $result->status, $status);
            $this->assertSame('WB2026001', $result->supplierOrderNo);
        }

        // 40001 无订单：只记录，订单状态不动
        $this->respond(['message' => '无订单', 'data' => [], 'code' => 40001]);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertFalse($result->callFailed);
        $this->assertStringContainsString('只记录', $result->message);

        // 其他 code、没有状态、订单号对不上、没拿到返回：不处理订单
        foreach ([
            ['message' => '签名错误', 'data' => [], 'code' => 1],
            ['message' => '获取成功', 'data' => [], 'code' => 0],
            ['message' => '获取成功', 'data' => ['out_order_no' => 'OTHER'] + $this->orderData('FINISH'), 'code' => 0],
            ['message' => '没有 code'],
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
        $this->respond(['message' => '获取成功', 'data' => ['balance' => 1013.9], 'code' => 0]);
        $this->assertSame('1013.9', $this->driver()->balance(self::CONFIG));
        $sent = $this->http->requests[0];
        $this->assertSame('http://lx.test/api/balance', $sent['url']);
        $this->assertSame(['appid' => 'WBHFKYRPK0', 'sign' => strtoupper(md5('appid=WBHFKYRPK0&secret=lx_secret'))], $sent['payload']);

        foreach ([['message' => '签名错误', 'data' => [], 'code' => 1], ['message' => '获取成功', 'data' => [], 'code' => 0], ['message' => 'x']] as $body) {
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
        $this->respond(['message' => '获取成功', 'data' => ['list' => [
            ['id' => 24, 'name' => '全国三网快充40元', 'unit' => '元', 'capacity' => '40', 'price' => 40, 'operator' => '电信,联通,移动', 'region' => '广东省广州市,广东省韶关市'],
            ['id' => 25, 'name' => '移动流量1G', 'unit' => 'G', 'capacity' => '1', 'price' => 9.5, 'operator' => '移动', 'region' => '全国通用'],
            ['name' => '没有 id'],
        ]], 'code' => 0]);
        $products = $this->driver()->upstreamProducts(self::CONFIG);

        $this->assertSame('http://lx.test/api/product', $this->http->requests[0]['url']);
        $this->assertCount(2, $products);
        $this->assertSame([
            'code' => '24',
            'name' => '全国三网快充40元',
            'price' => '40',
            'face_value' => 40,
            'operators' => ['cmcc', 'cucc', 'ctcc'],
            'on_sale' => true,
            'note' => '广东省广州市,广东省韶关市',
        ], $products[0]->toArray());
        // 单位不是「元」：不当面值
        $this->assertNull($products[1]->faceValue);
        $this->assertSame(['cmcc'], $products[1]->operators);

        $this->respond(['message' => '签名错误', 'data' => [], 'code' => 1]);
        $this->expectException(RuntimeException::class);
        $this->driver()->upstreamProducts(self::CONFIG);
    }

    public function testCallbackVerifiesSignatureAndCompletesOrder()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['message' => '订单已提交', 'data' => ['order_no' => 'WB2026001'], 'code' => 0]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame($attempt->attempt_no, $this->http->requests[0]['payload']['out_order_no']);
        $this->assertSame('ext', $this->http->requests[0]['payload']['product_id']);
        $this->assertArrayNotHasKey('notify_url', $this->http->requests[0]['payload'], '回调地址在上游后台配置，不随单传');

        // 签名不对、密钥不对：拒绝，订单不变
        $forged = $this->callbackParams($attempt->attempt_no, 'FINISH');
        $forged['sign'] = str_repeat('0', 32);
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $forged));
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'FINISH', [], 'wrong')));
        $tampered = $this->callbackParams($attempt->attempt_no, 'FAILED');
        $tampered['order_status'] = 'FINISH';
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $tampered));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // PASS / RUNNING：回 success 但不处理订单
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'RUNNING')));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // JSON 回调也能验签
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'FINISH'), true));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
    }

    public function testCallbackFailureFailsOrder()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['message' => '订单已提交', 'data' => ['order_no' => 'WB2026001'], 'code' => 0]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'FAILED', ['error_msg' => '无效的金额'])));
        $attempt = OrderAttempt::find($attempt->id);
        $this->assertSame(OrderAttempt::STATUS_FAILED, $attempt->status);
        $this->assertStringContainsString('无效的金额', $attempt->message);

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
        $supplier->update(['driver' => 'linxian', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
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
     * @return array<string, string>
     */
    private function orderData(string $status): array
    {
        return ['order_no' => 'WB2026001', 'out_order_no' => 'A0001', 'order_status' => $status, 'order_status_text' => ''];
    }

    /**
     * 照文档字段造一份回调，$extra 覆盖或追加字段；签名算全部字段。
     *
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function callbackParams(string $attemptNo, string $status, array $extra = [], string $secret = 'lx_secret'): array
    {
        $params = $extra + [
            'error_msg' => '',
            'order_no' => 'WB2026001',
            'isp_order_no' => '',
            'out_order_no' => $attemptNo,
            'order_status' => $status,
            'order_status_text' => '充值中',
        ];
        $params['sign'] = LinxianDriver::sign($params, $secret);

        return $params;
    }

    /**
     * 回调是 POST，文档没说是表单还是 JSON，两种都测。
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: string}
     */
    private function supplierCallback(Supplier $supplier, array $params, bool $json = false): array
    {
        $options = $json ? ['json' => $params, 'headers' => ['Content-Type' => 'application/json']] : ['form_params' => $params];
        $response = $this->client->request('POST', "/notify/supplier/{$supplier->code}", $options);

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    private function request(): RechargeRequest
    {
        return new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', '24', self::CONFIG);
    }

    private function driver(): LinxianDriver
    {
        return ApplicationContext::getContainer()->get(LinxianDriver::class);
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
