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
use App\Supplier\Driver\ShangtengDriver;
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
 * @internal
 * @coversNothing
 */
class ShangtengDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const CONFIG = [
        'api_url' => 'https://st.test/',
        'user_id' => '10086',
        'api_key' => 'st_key',
        'notify_url' => 'https://pb.test/notify/supplier/st',
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
        [$json, $sign] = ShangtengDriver::sign(['recharge_account' => '13800138000', 'notify_url' => 'https://a.com/n', 'external_orderno' => '中文'], '1758153600', 'k');

        // 按参数名升序，斜杠和中文不转义
        $this->assertSame('{"external_orderno":"中文","notify_url":"https://a.com/n","recharge_account":"13800138000"}', $json);
        $this->assertSame(sha1('1758153600' . $json . 'k'), $sign);
        // 没有参数时签 {}
        $this->assertSame(['{}', sha1('1758153600{}k')], ShangtengDriver::sign([], '1758153600', 'k'));
    }

    public function testRechargeSendsSignedJsonAndMapsResults()
    {
        $this->respond(['status' => 200, 'msg' => '提交成功']);
        $result = $this->driver()->recharge($this->request());

        $this->assertSame(RechargeStatus::Processing, $result->status);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_RAW', $sent['method']);
        $this->assertSame('https://st.test/api/Order/create', $sent['url']);
        $headers = $sent['payload']['headers'];
        $this->assertSame('10086', $headers['Userid']);
        $this->assertSame(sha1($headers['Timestamp'] . $sent['payload']['body'] . 'st_key'), $headers['Sign']);
        $this->assertSame([
            'external_orderno' => 'A0001',
            'face_value' => '100',
            'notify_url' => self::CONFIG['notify_url'],
            'product_id' => '12',
            'recharge_account' => '13800138000',
        ], json_decode($sent['payload']['body'], true));
        $this->assertSame($sent['payload']['body'], $result->request);

        // 文档里没有的面值不传 face_value
        $this->http->requests = [];
        $this->driver()->recharge($this->request(30));
        $this->assertArrayNotHasKey('face_value', json_decode($this->http->requests[0]['payload']['body'], true));

        // status 非 200：没建单，失败
        $this->respond(['status' => 411, 'msg' => '余额不足']);
        $result = $this->driver()->recharge($this->request());
        $this->assertSame(RechargeStatus::Failed, $result->status);
        $this->assertSame('上游拒单：[411] 余额不足', $result->message);
        $this->respond(['status' => 500, 'msg' => '系统错误'], 500);
        $this->assertSame(RechargeStatus::Failed, $this->driver()->recharge($this->request())->status);

        // 返回无法解析：不知道建没建单，处理中，等查单
        $this->http->responses = [['status' => 502, 'body' => '<html>Bad Gateway</html>']];
        $this->assertSame(RechargeStatus::Processing, $this->driver()->recharge($this->request())->status);
    }

    public function testQueryMapsStatuses()
    {
        $expected = [0 => RechargeStatus::Processing, 1 => RechargeStatus::Processing, 2 => RechargeStatus::Success, 3 => RechargeStatus::Failed, 4 => RechargeStatus::Failed, 5 => RechargeStatus::Failed];
        foreach ($expected as $status => $mapped) {
            $this->respond(['status' => 200, 'data' => $this->order($status)]);
            $result = $this->driver()->query($this->request());
            $this->assertSame($mapped, $result->status, "status {$status}");
            $this->assertSame('ST230923483295', $result->supplierOrderNo);
        }
        $sent = $this->http->requests[0];
        $this->assertSame('https://st.test/api/Order/query', $sent['url']);
        $this->assertSame('{"external_orderno":"A0001"}', $sent['payload']['body']);

        $this->respond(['status' => 200, 'data' => $this->order(5, '号码已停机')]);
        $this->assertSame('号码已停机', $this->driver()->query($this->request())->message);

        // 拿不到明确结果的都是处理中：查不到 / 411、单号对不上、没有状态
        $this->respond(['status' => 411, 'msg' => '订单不存在']);
        $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status);
        $this->respond(['status' => 200, 'data' => ['external_orderno' => 'OTHER'] + $this->order(5)]);
        $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status);
        $this->respond(['status' => 200, 'data' => ['external_orderno' => 'A0001']]);
        $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status);
    }

    public function testBalance()
    {
        $this->respond(['status' => 200, 'data' => ['phone_balance' => 1000.5, 'power_balance' => 0, 'member_balance' => 0]]);
        $this->assertSame('1000.5', $this->driver()->balance(self::CONFIG));
        $this->assertSame('https://st.test/api/User/balance', $this->http->requests[0]['url']);
        $this->assertSame('{}', $this->http->requests[0]['payload']['body']);

        $this->respond(['status' => 411, 'msg' => '签名错误']);
        $this->expectException(RuntimeException::class);
        $this->driver()->balance(self::CONFIG);
    }

    public function testCallbackIsConfirmedByQuery()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['status' => 200, 'msg' => '提交成功']);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 没有订单号：拒绝
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, ['status' => 2]));

        // 回调说成功，查单还在充值中：以查单为准，订单不变
        $this->http->requests = [];
        $this->respond(['status' => 200, 'data' => $this->order(1, '', $attempt->attempt_no)]);
        $this->assertSame([200, 'ok'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 2)));
        $this->assertSame('/api/Order/query', parse_url($this->http->requests[0]['url'], PHP_URL_PATH));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame('ST230923483295', OrderAttempt::find($attempt->id)->supplier_order_no);

        // 查单也确认成功
        $this->respond(['status' => 200, 'data' => $this->order(2, '', $attempt->attempt_no)]);
        $this->assertSame([200, 'ok'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 2)));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
    }

    public function testRejectedSubmitFailsOrderAndRefunds()
    {
        [$merchant, $product, $mobile] = $this->scenario();

        $this->respond(['status' => 411, 'msg' => '商品已下架']);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);

        $order = Order::find($order->id);
        $this->assertSame(Order::STATUS_FAILED, $order->status);
        $this->assertStringContainsString('商品已下架', $order->fail_reason);
        $this->assertSame(OrderAttempt::STATUS_FAILED, OrderAttempt::where('order_id', $order->id)->first()->status);
        $this->assertSame('200.00', Merchant::find($merchant->id)->balance);
    }

    public function testConfirmedFailureFailsOrderWhenNoOtherSupplierCoversIt()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['status' => 200, 'msg' => '提交成功']);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        $this->respond(['status' => 200, 'data' => $this->order(5, '号码已停机', $attempt->attempt_no)]);
        $this->assertSame([200, 'ok'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 5)));
        $this->assertSame(OrderAttempt::STATUS_FAILED, OrderAttempt::find($attempt->id)->status);

        // 回调后推的重新提交任务：这个省份只有这一家，订单直接失败退款，不会提交给别家
        $this->process()->submit($order->id);
        $order = Order::find($order->id);
        $this->assertSame(Order::STATUS_FAILED, $order->status);
        $this->assertStringContainsString('号码已停机', $order->fail_reason);
        $this->assertSame(1, OrderAttempt::where('order_id', $order->id)->count());
        $this->assertSame('200.00', Merchant::find($merchant->id)->balance);
    }

    /**
     * @return array{0: Merchant, 1: Product, 2: string, 3: Supplier}
     */
    private function scenario(): array
    {
        $supplier = $this->createSupplier(['广东']);
        $supplier->update(['driver' => 'shangteng', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
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
     * @return array<string, mixed>
     */
    private function callbackParams(string $attemptNo, int $status): array
    {
        return ['external_orderno' => $attemptNo, 'orderno' => 'ST230923483295', 'status' => $status, 'msg' => ''];
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

    private function request(int $faceValue = 100): RechargeRequest
    {
        return new RechargeRequest('A0001', '13800138000', $faceValue, 'cmcc', '广东', '12', self::CONFIG);
    }

    private function driver(): ShangtengDriver
    {
        return ApplicationContext::getContainer()->get(ShangtengDriver::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function order(int $status, string $msg = '', string $attemptNo = 'A0001'): array
    {
        return [
            'external_orderno' => $attemptNo,
            'orderno' => 'ST230923483295',
            'status' => $status,
            'msg' => $msg,
            'product_id' => '12',
            'recharge_account' => '13800138000',
            'amount' => '98.80',
        ];
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
