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
use App\Supplier\Driver\PikoudaiDriver;
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
use RuntimeException;

use function Hyperf\Support\make;

/**
 * 皮口袋驱动：键值直连 md5 签名、结果判定、回调验签（docs/话费接口.md）。
 *
 * @internal
 * @coversNothing
 */
class PikoudaiDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const CONFIG = [
        'api_url' => 'https://pkd.test/',
        'merchant_no' => '81447134257641295872',
        'secret' => 'pkd_secret',
        'notify_url' => 'https://pb.test/notify/supplier/pkd',
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

    public function testSignFollowsDocumentExample()
    {
        // 文档里的影音会员示例：升序、键值直连、空值也参与、密钥拼最后、小写
        $this->assertSame(
            md5('equity_number1638861816021merchant_no11705099001294098432merchant_order_no91675220213notify_urlhttp://106.54.222.93:8002/TestApi/notifyproduct_no31555504031370186752ad4bde88dfe44ba393264528de2e073f'),
            PikoudaiDriver::sign([
                'product_no' => '31555504031370186752',
                'notify_url' => 'http://106.54.222.93:8002/TestApi/notify',
                'merchant_order_no' => '91675220213',
                'merchant_no' => '11705099001294098432',
                'equity_number' => '1638861816021',
                'sign' => 'ignored',
            ], 'ad4bde88dfe44ba393264528de2e073f')
        );
        $this->assertSame(md5('a1bc3xx'), PikoudaiDriver::sign(['c' => 3, 'b' => '', 'a' => '1'], 'xx'));
    }

    public function testRechargeSendsSignedJsonAndMapsResults()
    {
        $this->respond(['code' => 1000, 'message' => '成功', 'sign' => 'x', 'data' => ['orderNo' => '101447915098520817664', 'merchantOrderNo' => 'A0001', 'price' => 48.5]]);
        $result = $this->driver()->recharge($this->request());

        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertSame('101447915098520817664', $result->supplierOrderNo);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_JSON', $sent['method']);
        $this->assertSame('https://pkd.test/api/order/phone-by-product', $sent['url']);
        $payload = $sent['payload'];
        $this->assertSame('81447134257641295872', $payload['merchant_no']);
        $this->assertSame('A0001', $payload['merchant_order_no']);
        $this->assertSame('31637273772136468485', $payload['product_no']);
        $this->assertSame(1, $payload['num']);
        $this->assertSame('13800138000', $payload['equity_number']);
        $this->assertSame(self::CONFIG['notify_url'], $payload['notify_url']);
        $this->assertSame(PikoudaiDriver::sign($payload, 'pkd_secret'), $payload['sign']);
        $this->assertArrayNotHasKey('secret', $payload);

        // 校验类错误码：上游明确拒单，失败
        foreach (['1001', '1002', '1004', '1005', '1006', '1008', '1011', '1012'] as $code) {
            $this->respond(['code' => (int) $code, 'message' => '拒单' . $code]);
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Failed, $result->status, $code);
            $this->assertStringContainsString("[{$code}] 拒单{$code}", $result->message);
        }
        $this->respond(['code' => 1004, 'message' => '']);
        $this->assertStringContainsString('[1004] 额度不足', $this->driver()->recharge($this->request())->message);

        // 上游有返回、不是 1000：订单号重复、系统繁忙、文档外的错误码也都判失败
        foreach (['1003', '1007', '9999'] as $code) {
            $this->respond(['code' => (int) $code, 'message' => '']);
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Failed, $result->status, $code);
            $this->assertFalse($result->callFailed, $code);
            $this->assertStringContainsString("[{$code}]", $result->message);
        }
        $this->respond(['code' => 1007, 'message' => '']);
        $this->assertStringContainsString('[1007] 系统繁忙', $this->driver()->recharge($this->request())->message);

        // HTTP 出错、返回无法解析：没有拿到返回值，按接口异常处理，带上原始报文
        $this->http->responses = [['status' => 502, 'body' => 'Bad Gateway']];
        $result = $this->driver()->recharge($this->request());
        $this->assertTrue($result->callFailed);
        $this->assertStringContainsString('没有拿到上游返回', $result->message);
        $this->assertStringContainsString('Bad Gateway', $result->response);
        $this->assertStringContainsString('A0001', $result->request);
    }

    public function testQueryMapsStatuses()
    {
        $statuses = ['Success' => RechargeStatus::Success, 'Failure' => RechargeStatus::Failed, 'Processing' => RechargeStatus::Processing];
        foreach ($statuses as $status => $mapped) {
            $this->respond(['code' => 1000, 'message' => '成功', 'data' => ['orderNo' => 'P1', 'merchantOrderNo' => 'A0001', 'orderStatus' => $status, 'extraInfo' => '110000000000000']]);
            $result = $this->driver()->query($this->request());
            $this->assertSame($mapped, $result->status, $status);
            $this->assertSame('P1', $result->supplierOrderNo);
        }
        $sent = $this->http->requests[0];
        $this->assertSame('https://pkd.test/api/order/getorder', $sent['url']);
        $this->assertSame(['merchant_order_no', 'merchant_no', 'sign'], array_keys($sent['payload']));
        $this->assertSame(PikoudaiDriver::sign($sent['payload'], 'pkd_secret'), $sent['payload']['sign']);

        // 查不到订单：失败
        $this->respond(['code' => 2001, 'message' => '查不到订单']);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Failed, $result->status);
        $this->assertStringContainsString('[2001] 查不到订单', $result->message);

        // 出错、单号对不上、没有状态：处理中
        foreach ([
            ['code' => 1001, 'message' => '验签失败'],
            ['code' => 1000, 'message' => '成功', 'data' => ['merchantOrderNo' => 'OTHER', 'orderStatus' => 'Success']],
            ['code' => 1000, 'message' => '成功', 'data' => ['merchantOrderNo' => 'A0001']],
        ] as $body) {
            $this->respond($body);
            $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status, json_encode($body));
        }
    }

    public function testBalance()
    {
        $this->respond(['code' => 1000, 'message' => '成功', 'data' => ['balance' => 668889.5, 'credit' => 100]]);
        $this->assertSame('668889.5', $this->driver()->balance(self::CONFIG));
        $sent = $this->http->requests[0];
        $this->assertSame('https://pkd.test/api/merchant/balance', $sent['url']);
        $this->assertMatchesRegularExpression('/^\d{13}$/', $sent['payload']['timestamp']);
        $this->assertSame(PikoudaiDriver::sign($sent['payload'], 'pkd_secret'), $sent['payload']['sign']);

        $this->respond(['code' => 1001, 'message' => '验签失败']);
        $this->expectException(RuntimeException::class);
        $this->driver()->balance(self::CONFIG);
    }

    public function testCallbackVerifiesSignatureAndCompletesOrder()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['code' => 1000, 'message' => '成功', 'data' => ['orderNo' => 'P000001', 'merchantOrderNo' => 'x', 'price' => 98.8]]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame('P000001', $attempt->supplier_order_no);

        // 签名不对、密钥不对、商户编号不对：拒绝，订单不变
        $forged = $this->callbackParams($attempt->attempt_no, 'Success');
        $forged['Sign'] = str_repeat('0', 32);
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $forged));
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'Success', [], 'wrong')));
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'Success', ['MerchantNo' => '1'])));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // Payment 是 JSON 数字 98.80 时按原样参与验签（json_decode 会变成 98.8）
        $raw = str_replace('"Payment":"98.80"', '"Payment":98.80', json_encode($this->callbackParams($attempt->attempt_no, 'Success'), JSON_UNESCAPED_SLASHES));
        $this->assertStringContainsString('"Payment":98.80', $raw);
        $parsed = $this->driver()->parseCallback(new Psr7Request('POST', '/notify', ['Content-Type' => 'application/json'], new SwooleStream($raw)), self::CONFIG);
        $this->assertTrue($parsed->verified);
        $this->assertSame($attempt->attempt_no, $parsed->attemptNo);
        $this->assertSame(RechargeStatus::Success, $parsed->result->status);

        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'Success')));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
    }

    public function testCallbackFailureFailsOrder()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['code' => 1000, 'message' => '成功', 'data' => ['orderNo' => 'P000002']]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'Failure', ['ChannelExtraInfo' => ''])));
        $this->assertSame(OrderAttempt::STATUS_FAILED, OrderAttempt::find($attempt->id)->status);

        // 回调后推的重新提交任务：这个省份只有这一家，订单直接失败退款
        $this->process()->submit($order->id);
        $this->assertSame(Order::STATUS_FAILED, Order::find($order->id)->status);
        $this->assertSame('200.00', Merchant::find($merchant->id)->balance);
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
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertStringContainsString('没有拿到上游返回', $attempt->message);
        $this->assertStringContainsString('Bad Gateway', $attempt->response);
        $this->assertSame(1, OrderAttempt::where('order_id', $order->id)->count(), '不换下一家');
    }

    /**
     * @return array{0: Merchant, 1: Product, 2: string, 3: Supplier}
     */
    private function scenario(): array
    {
        $supplier = $this->createSupplier(['广东']);
        $supplier->update(['driver' => 'pikoudai', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
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
     * 照文档字段造一份回调，$extra 覆盖或追加字段，全部参与签名（字段名区分大小写）。
     *
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function callbackParams(string $attemptNo, string $status, array $extra = [], string $secret = 'pkd_secret'): array
    {
        $params = $extra + [
            'MerchantOrderNo' => $attemptNo,
            'MerchantNo' => '81447134257641295872',
            'Payment' => '98.80',
            'Status' => $status,
            'ChannelExtraInfo' => '110000000000000',
        ];
        $params['Sign'] = PikoudaiDriver::sign($params, $secret);

        return $params;
    }

    /**
     * 回调是 JSON POST。
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: string}
     */
    private function supplierCallback(Supplier $supplier, array $params): array
    {
        $response = $this->client->request('POST', "/notify/supplier/{$supplier->code}", ['json' => $params, 'headers' => ['Content-Type' => 'application/json']]);

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    private function request(): RechargeRequest
    {
        return new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', '31637273772136468485', self::CONFIG);
    }

    private function driver(): PikoudaiDriver
    {
        return ApplicationContext::getContainer()->get(PikoudaiDriver::class);
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
