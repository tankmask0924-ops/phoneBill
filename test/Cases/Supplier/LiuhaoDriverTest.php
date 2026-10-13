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
use App\Supplier\Driver\LiuhaoDriver;
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
 * 刘浩驱动：GET + 固定顺序签名、结果判定、回调验签后以查单为准（docs/充值API.md）。
 *
 * @internal
 * @coversNothing
 */
class LiuhaoDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const CONFIG = [
        'api_url' => 'http://118.25.11.93:8089/',
        'user' => 'test',
        'key' => 'AAAAAA',
        'notify_url' => 'https://pb.test/notify/supplier/lh',
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
        $this->assertSame(
            md5('method=charge&user=test&custom_number=8903849043&mobile=15566667777&product=100MAAAAAA'),
            LiuhaoDriver::sign(['method' => 'charge', 'user' => 'test', 'custom_number' => '8903849043', 'mobile' => '15566667777', 'product' => '100M'], 'AAAAAA')
        );
        // 文档里的回调示例
        $this->assertSame('b254630f18346917b989f772c0a7fda8', LiuhaoDriver::callbackSign('15498785038', '8902027384', 'AAAAAA'));
    }

    public function testRechargeSendsSignedGetAndMapsResults()
    {
        $this->respond(['ResultCode' => '0000', 'ResultMessage' => '受理成功']);
        $result = $this->driver()->recharge($this->request());

        $this->assertSame(RechargeStatus::Processing, $result->status);
        $sent = $this->http->requests[0];
        $this->assertSame('GET', $sent['method']);
        $this->assertSame('http://118.25.11.93:8089/flow/charge.aspx', $sent['url']);
        $this->assertSame(
            ['method' => 'charge', 'user' => 'test', 'custom_number' => 'A0001', 'mobile' => '13800138000', 'product' => '100', 'network' => '1', 'area' => '广东'],
            array_diff_key($sent['payload'], ['sign' => true])
        );
        // network、area 不参与签名
        $this->assertSame(md5('method=charge&user=test&custom_number=A0001&mobile=13800138000&product=100AAAAAA'), $sent['payload']['sign']);

        // 文档列出的失败码：失败；BOM 也能解析
        foreach (['9522', '9500', '9511', '9533', '9555'] as $code) {
            $this->http->responses = [['status' => 200, 'body' => "\xEF\xBB\xBF" . json_encode(['ResultCode' => $code, 'ResultMessage' => '拒单' . $code], JSON_UNESCAPED_UNICODE)]];
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Failed, $result->status, $code);
            $this->assertStringContainsString("[{$code}] 拒单{$code}", $result->message);
        }
        $this->respond(['ResultCode' => '9555', 'ResultMessage' => '']);
        $this->assertStringContainsString('[9555] 余额不足', $this->driver()->recharge($this->request())->message);

        // 其他原因：按接口异常处理，带上原始报文；describe 在上游没给描述时用文档说明
        $this->respond(['ResultCode' => '1111', 'ResultMessage' => '']);
        $result = $this->driver()->recharge($this->request());
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertTrue($result->callFailed);
        $this->assertStringContainsString('[1111]', $result->message);
        $this->assertStringContainsString('1111', $result->response);
        $this->assertStringContainsString('A0001', $result->request);

        // 订单号重复、表外响应码、HTTP 出错：处理中
        $this->respond(['ResultCode' => '9545', 'ResultMessage' => '订单号重复']);
        $result = $this->driver()->recharge($this->request());
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertFalse($result->callFailed);
        $this->assertStringContainsString('之前已提交过', $result->message);
        $this->respond(['ResultCode' => '8888', 'ResultMessage' => 'x']);
        $this->assertSame(RechargeStatus::Processing, $this->driver()->recharge($this->request())->status);
        $this->http->responses = [['status' => 500, 'body' => 'Server Error']];
        $this->assertSame(RechargeStatus::Processing, $this->driver()->recharge($this->request())->status);

        // 不认识的运营商不传 network
        $this->respond(['ResultCode' => '0000', 'ResultMessage' => '受理成功']);
        $this->driver()->recharge(new RechargeRequest('A0002', '19200000000', 100, 'cbn', '广东', '100', self::CONFIG));
        $this->assertArrayNotHasKey('network', end($this->http->requests)['payload']);
    }

    public function testQueryMapsStatuses()
    {
        $statuses = ['2000' => RechargeStatus::Success, '3000' => RechargeStatus::Failed, '1000' => RechargeStatus::Processing];
        foreach ($statuses as $status => $mapped) {
            $this->respond(['ResultCode' => '0000', 'ResultMessage' => '号码已停机', 'OrderStatus' => (string) $status, 'Voucher' => '']);
            $this->assertSame($mapped, $this->driver()->query($this->request())->status, (string) $status);
        }
        $sent = $this->http->requests[0];
        $this->assertSame(['method' => 'orderstatus', 'user' => 'test', 'custom_number' => 'A0001', 'sign' => md5('method=orderstatus&user=test&custom_number=A0001AAAAAA')], $sent['payload']);

        $this->respond(['ResultCode' => '0000', 'ResultMessage' => '号码已停机', 'OrderStatus' => '3000']);
        $this->assertSame('号码已停机', $this->driver()->query($this->request())->message);

        // 订单不存在：失败
        $this->respond(['ResultCode' => '2222', 'ResultMessage' => '订单不存在']);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Failed, $result->status);
        $this->assertStringContainsString('[2222] 订单不存在', $result->message);

        // 调用验证失败、没有状态、无法解析：处理中
        foreach ([['ResultCode' => '1111', 'ResultMessage' => '调用验证失败'], ['ResultCode' => '0000', 'ResultMessage' => 'x']] as $body) {
            $this->respond($body);
            $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status, json_encode($body));
        }
        $this->http->responses = [['status' => 502, 'body' => '']];
        $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status);
    }

    public function testBalance()
    {
        $this->respond(['ResultCode' => '0000', 'ResultMessage' => '查询成功', 'Balance' => '888.88']);
        $this->assertSame('888.88', $this->driver()->balance(self::CONFIG));
        $this->assertSame(['method' => 'balance', 'user' => 'test', 'sign' => md5('method=balance&user=testAAAAAA')], $this->http->requests[0]['payload']);

        $this->respond(['ResultCode' => '1111', 'ResultMessage' => '查询失败']);
        $this->expectException(RuntimeException::class);
        $this->driver()->balance(self::CONFIG);
    }

    public function testCallbackVerifiesSignatureThenTrustsQuery()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['ResultCode' => '0000', 'ResultMessage' => '受理成功']);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $requests = count($this->http->requests);

        // 签名不对、秘钥不对：拒绝，也不去查单
        $forged = $this->callbackParams($attempt->attempt_no, $mobile, '2000');
        $forged['Sign'] = str_repeat('0', 32);
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $forged));
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, $mobile, '2000', 'wrong')));
        $this->assertCount($requests, $this->http->requests);

        // 回调说成功、查单还是充值中：以查单为准，保持处理中
        $this->respond(['ResultCode' => '0000', 'ResultMessage' => '充值中', 'OrderStatus' => '1000']);
        $this->assertSame([200, 'ok'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, $mobile, '2000')));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame(['method' => 'orderstatus', 'user' => 'test', 'custom_number' => $attempt->attempt_no], array_diff_key(end($this->http->requests)['payload'], ['sign' => true]));

        // 查单出错：照常应答，保持处理中
        $this->http->responses = [['status' => 500, 'body' => '']];
        $this->assertSame([200, 'ok'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, $mobile, '2000')));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 查单成功：订单成功（表单回调也认）
        $this->respond(['ResultCode' => '0000', 'ResultMessage' => '充值成功', 'OrderStatus' => '2000', 'Voucher' => 'V1']);
        $this->assertSame([200, 'ok'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, $mobile, '2000'), false));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
    }

    public function testCallbackFailureFailsOrder()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['ResultCode' => '0000', 'ResultMessage' => '受理成功']);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        $this->respond(['ResultCode' => '0000', 'ResultMessage' => '号码已停机', 'OrderStatus' => '3000']);
        $this->assertSame([200, 'ok'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, $mobile, '3000')));
        $attempt = OrderAttempt::find($attempt->id);
        $this->assertSame(OrderAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('号码已停机', $attempt->message);

        // 回调后推的重新提交任务：这个省份只有这一家，订单直接失败退款
        $this->process()->submit($order->id);
        $this->assertSame(Order::STATUS_FAILED, Order::find($order->id)->status);
        $this->assertSame('200.00', Merchant::find($merchant->id)->balance);
    }

    public function testOtherReasonMarksOrderAbnormal()
    {
        [$merchant, $product, $mobile] = $this->scenario();

        $this->respond(['ResultCode' => '1111', 'ResultMessage' => '其他原因']);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);

        $order = Order::find($order->id);
        $this->assertSame(Order::STATUS_ABNORMAL, $order->status);
        $this->assertTrue((bool) $order->manual_only);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertStringContainsString('[1111] 其他原因', $attempt->message);
        $this->assertStringContainsString('1111', $attempt->response);
        $this->assertSame(1, OrderAttempt::where('order_id', $order->id)->count(), '不换下一家');
    }

    /**
     * @return array{0: Merchant, 1: Product, 2: string, 3: Supplier}
     */
    private function scenario(): array
    {
        $supplier = $this->createSupplier(['广东']);
        $supplier->update(['driver' => 'liuhao', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
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
     * 照文档字段造一份回调。
     *
     * @return array<string, string>
     */
    private function callbackParams(string $attemptNo, string $mobile, string $status, string $key = 'AAAAAA'): array
    {
        return [
            'Custom_Number' => $attemptNo,
            'Mobile' => $mobile,
            'OrderStatus' => $status,
            'ResultMessage' => $status === '2000' ? '充值成功' : '充值失败',
            'Sign' => LiuhaoDriver::callbackSign($mobile, $attemptNo, $key),
            'Voucher' => '',
        ];
    }

    /**
     * 文档示例是 JSON POST，$json 为 false 时发表单。
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: string}
     */
    private function supplierCallback(Supplier $supplier, array $params, bool $json = true): array
    {
        $options = $json ? ['json' => $params, 'headers' => ['Content-Type' => 'application/json']] : ['form_params' => $params];
        $response = $this->client->request('POST', "/notify/supplier/{$supplier->code}", $options);

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    private function request(): RechargeRequest
    {
        return new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', '100', self::CONFIG);
    }

    private function driver(): LiuhaoDriver
    {
        return ApplicationContext::getContainer()->get(LiuhaoDriver::class);
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
