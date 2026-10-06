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
use App\Supplier\Driver\OpenPlatformDriver;
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

use function Hyperf\Support\make;

/**
 * @internal
 * @coversNothing
 */
class OpenPlatformDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const CONFIG = [
        'api_url' => 'https://upstream.test/open-api/',
        'app_key' => 'ak_up',
        'app_secret' => 'up_secret',
        'notify_url' => 'https://pb.test/notify/supplier/up',
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

    public function testSignMatchesDocumentExample()
    {
        $params = ['app_key' => 'ak_demo', 'timestamp' => '1758153600', 'nonce' => 'e4b1c2d3f4a5b6c7', 'business_line' => 'recharge'];

        $this->assertSame('e89290be61170ed37c78476fd421127dd70de8b5ff5f2fd22742efb47ffac990', OpenPlatformDriver::sign($params, 'demo_secret'));
        $this->assertSame(OpenPlatformDriver::sign($params + ['empty' => ''], 's'), OpenPlatformDriver::sign($params + ['empty' => '', 'sign' => 'x'], 's'), 'sign 不参与签名');
        $this->assertNotSame(OpenPlatformDriver::sign($params, 's'), OpenPlatformDriver::sign($params + ['empty' => ''], 's'), '空值也参与签名');
    }

    public function testRechargeSendsSignedFormAndMapsResults()
    {
        $this->respond(['code' => 0, 'message' => 'ok', 'data' => $this->order('processing')]);
        $result = $this->driver()->recharge($this->request());

        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertSame('R20261006000001', $result->supplierOrderNo);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_FORM', $sent['method']);
        $this->assertSame('https://upstream.test/open-api/orders/recharge', $sent['url']);
        $this->assertSame('A0001', $sent['payload']['merchant_order_no']);
        $this->assertSame('12', $sent['payload']['product_id']);
        $this->assertSame('13800138000', $sent['payload']['recharge_account']);
        $this->assertSame(self::CONFIG['notify_url'], $sent['payload']['callback_url']);
        $this->assertSame('ak_up', $sent['payload']['app_key']);
        $this->assertSame(OpenPlatformDriver::sign($sent['payload'], 'up_secret'), $sent['payload']['sign']);

        // 受理时就失败（上游余额不足）：失败，带上失败原因码
        $this->respond(['code' => 0, 'message' => 'ok', 'data' => $this->order('failed', 43001, '可用余额不足')]);
        $result = $this->driver()->recharge($this->request());
        $this->assertSame(RechargeStatus::Failed, $result->status);
        $this->assertSame('[43001] 可用余额不足', $result->message);

        // 上游拒单（没有建单）：失败，可以换下一家
        $this->respond(['code' => 42002, 'message' => '商品未上架', 'data' => null]);
        $this->assertSame(RechargeStatus::Failed, $this->driver()->recharge($this->request())->status);
        $this->respond(['code' => 40005, 'message' => '签名错误', 'data' => null], 401);
        $this->assertSame(RechargeStatus::Failed, $this->driver()->recharge($this->request())->status);

        // 拿不准有没有建单的：处理中，之后查单
        $this->respond(['code' => 50000, 'message' => '系统繁忙', 'data' => null], 500);
        $this->assertSame(RechargeStatus::Processing, $this->driver()->recharge($this->request())->status);
        $this->http->responses = [['status' => 502, 'body' => '<html>Bad Gateway</html>']];
        $this->assertSame(RechargeStatus::Processing, $this->driver()->recharge($this->request())->status);
    }

    public function testQueryMapsStatusAndResubmitsWhenUpstreamHasNoOrder()
    {
        $this->respond(['code' => 0, 'message' => 'ok', 'data' => $this->order('success')]);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Success, $result->status);
        $this->assertSame('GET', $this->http->requests[0]['method']);
        $this->assertSame('https://upstream.test/open-api/order', $this->http->requests[0]['url']);
        $this->assertSame('A0001', $this->http->requests[0]['payload']['merchant_order_no']);

        foreach (['cancelled', 'refunded'] as $status) {
            $this->respond(['code' => 0, 'message' => 'ok', 'data' => $this->order($status)]);
            $this->assertSame(RechargeStatus::Failed, $this->driver()->query($this->request())->status, $status);
        }

        // 上游查不到：用原单号补提交
        $this->http->requests = [];
        $this->http->responses = [
            $this->json(['code' => 42005, 'message' => '订单不存在', 'data' => null]),
            $this->json(['code' => 0, 'message' => 'ok', 'data' => $this->order('processing')]),
        ];
        $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status);
        $this->assertSame(['GET', 'POST_FORM'], array_column($this->http->requests, 'method'));
        $this->assertSame('A0001', $this->http->requests[1]['payload']['merchant_order_no']);

        // 补提交被拒：不判失败（原单可能其实存在），保持处理中，到时转异常人工处理
        $this->http->responses = [
            $this->json(['code' => 42005, 'message' => '订单不存在', 'data' => null]),
            $this->json(['code' => 42002, 'message' => '商品未上架', 'data' => null]),
        ];
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertStringContainsString('42002', $result->message);
    }

    public function testBalance()
    {
        $this->respond(['code' => 0, 'message' => 'ok', 'data' => ['available_balance' => '1000.00', 'frozen_balance' => '99.20']]);
        $this->assertSame('1000.00', $this->driver()->balance(self::CONFIG));
        $this->assertSame('https://upstream.test/open-api/balance', $this->http->requests[0]['url']);
    }

    public function testCallbackCompletesOrder()
    {
        [$merchant, $product, $mobile, $supplier] = $this->openPlatformScenario();

        $this->respond(['code' => 0, 'message' => 'ok', 'data' => $this->order('processing')]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame('R20261006000001', $attempt->supplier_order_no);

        // 签名不对：拒绝，订单不变
        $forged = $this->callbackParams($attempt->attempt_no, 'success');
        $forged['sign'] = str_repeat('0', 64);
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $forged));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 时间戳过期：拒绝
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'success', time() - 600)));

        $this->http->responses = [['status' => 200, 'body' => 'success']];
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'success')));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);

        // 成功后上游又退款：订单不自动改，等人工冲正
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'refunded')));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
        $this->assertSame(OrderAttempt::STATUS_SUCCESS, OrderAttempt::find($attempt->id)->status);
    }

    public function testFailedCallbackSwitchesToNextSupplier()
    {
        [$merchant, $product, $mobile, $supplier] = $this->openPlatformScenario();

        $this->respond(['code' => 0, 'message' => 'ok', 'data' => $this->order('processing')]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 'failed', null, ['fail_code' => '43002', 'fail_reason' => '商品暂时无法供货'])));
        $attempt = OrderAttempt::find($attempt->id);
        $this->assertSame(OrderAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('[43002] 商品暂时无法供货', $attempt->message);
    }

    /**
     * @return array{0: Merchant, 1: Product, 2: string, 3: Supplier}
     */
    private function openPlatformScenario(): array
    {
        $supplier = $this->createSupplier();
        $supplier->update(['driver' => 'open_platform', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
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
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function callbackParams(string $attemptNo, string $status, ?int $timestamp = null, array $extra = []): array
    {
        $params = [
            'order_no' => 'R20261006000001',
            'merchant_order_no' => $attemptNo,
            'business_line' => 'recharge',
            'status' => $status,
            'completed_at' => date('Y-m-d H:i:s'),
            'app_key' => 'ak_up',
            'timestamp' => (string) ($timestamp ?? time()),
            'nonce' => bin2hex(random_bytes(8)),
        ] + $extra;
        $params['sign'] = OpenPlatformDriver::sign($params, 'up_secret');

        return $params;
    }

    /**
     * @param array<string, string> $params
     * @return array{0: int, 1: string}
     */
    private function supplierCallback(Supplier $supplier, array $params): array
    {
        $response = $this->client->request('POST', "/notify/supplier/{$supplier->code}", ['form_params' => $params]);

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    private function request(): RechargeRequest
    {
        return new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', '12', self::CONFIG);
    }

    private function driver(): OpenPlatformDriver
    {
        return ApplicationContext::getContainer()->get(OpenPlatformDriver::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function order(string $status, ?int $failCode = null, ?string $failReason = null): array
    {
        return [
            'order_no' => 'R20261006000001',
            'merchant_order_no' => 'A0001',
            'business_line' => 'recharge',
            'status' => $status,
            'sale_price' => '99.20',
            'frozen_amount' => '99.20',
            'deducted_amount' => null,
            'refunded_amount' => '0.00',
            'completed_at' => null,
            'fail_code' => $failCode,
            'fail_reason' => $failReason,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(array $body, int $status = 200): void
    {
        $this->http->responses = [$this->json($body, $status)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: string}
     */
    private function json(array $body, int $status = 200): array
    {
        return ['status' => $status, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE)];
    }

    private function process(): OrderProcessService
    {
        return ApplicationContext::getContainer()->get(OrderProcessService::class);
    }
}
