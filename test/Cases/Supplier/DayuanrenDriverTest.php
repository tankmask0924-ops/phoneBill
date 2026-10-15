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
use App\Supplier\Driver\DayuanrenDriver;
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
 * 大猿人驱动：表单 + 大写 md5 签名、结果判定、回调验签（docs/大猿人API接口.md 第 9 节）。
 *
 * @internal
 * @coversNothing
 */
class DayuanrenDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const CONFIG = [
        'api_url' => 'https://dyr.test/',
        'userid' => '10001',
        'apikey' => 'dyr_key',
        'notify_url' => 'https://pb.test/notify/supplier/dyr',
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
        // 升序、不做 URL 编码、apikey 拼最后、大写
        $this->assertSame(
            strtoupper(md5('a=1&b=2&notify_url=http://x.com/n?a=1&apikey=xx')),
            DayuanrenDriver::sign(['b' => '2', 'notify_url' => 'http://x.com/n?a=1', 'a' => '1', 'sign' => 'ignored'], 'xx')
        );
    }

    public function testRechargeSendsSignedFormAndMapsResults()
    {
        $this->respond(['errno' => 0, 'errmsg' => '下单成功', 'data' => ['order_number' => 'XYZ111111', 'out_trade_num' => 'A0001']]);
        $result = $this->driver()->recharge($this->request());

        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertSame('XYZ111111', $result->supplierOrderNo);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_FORM', $sent['method']);
        $this->assertSame('https://dyr.test/yrapi.php/index/recharge', $sent['url']);
        $payload = $sent['payload'];
        $this->assertSame('A0001', $payload['out_trade_num']);
        $this->assertSame('68', $payload['product_id']);
        $this->assertSame('13800138000', $payload['mobile']);
        $this->assertSame(self::CONFIG['notify_url'], $payload['notify_url']);
        $this->assertSame('10001', $payload['userid']);
        $this->assertSame('100', $payload['amount']);
        $this->assertSame(DayuanrenDriver::sign($payload, 'dyr_key'), $payload['sign']);
        $this->assertArrayNotHasKey('apikey', $payload);
        $this->assertContainsOnly('string', $payload);

        // errno 非 0：上游明确提交失败
        $this->respond(['errno' => '1', 'errmsg' => '产品不存在']);
        $result = $this->driver()->recharge($this->request());
        $this->assertSame(RechargeStatus::Failed, $result->status);
        $this->assertStringContainsString('[1] 产品不存在', $result->message);

        // HTTP 非 200 但带着可解析的错误：有返回值，失败
        $this->respond(['errno' => '1', 'errmsg' => 'x'], 500);
        $this->assertSame(RechargeStatus::Failed, $this->driver()->recharge($this->request())->status);

        // 没有响应体、返回无法解析：没有拿到返回值，转异常人工处理
        $this->http->responses = [['status' => 200, 'body' => '']];
        $result = $this->driver()->recharge($this->request());
        $this->assertTrue($result->callFailed);
        $this->assertStringContainsString('没有拿到上游返回', $result->message);
        $this->http->responses = [['status' => 502, 'body' => '<html>Bad Gateway</html>']];
        $this->assertTrue($this->driver()->recharge($this->request())->callFailed);
    }

    public function testQueryMapsStates()
    {
        $states = ['1' => RechargeStatus::Success, '2' => RechargeStatus::Failed, '-1' => RechargeStatus::Failed, '0' => RechargeStatus::Processing, '3' => RechargeStatus::Processing];
        foreach ($states as $state => $mapped) {
            // data 是单个订单或订单列表都认
            $order = ['order_number' => 'CZH1', 'out_trade_num' => 'A0001', 'state' => (string) $state];
            $this->respond(['errno' => '0', 'errmsg' => '查询成功', 'data' => $state === 0 ? [$order] : $order]);
            $result = $this->driver()->query($this->request());
            $this->assertSame($mapped, $result->status, "state {$state}");
            $this->assertSame('CZH1', $result->supplierOrderNo);
        }
        $this->assertStringContainsString('部分成功', $result->message);
        $sent = $this->http->requests[0];
        $this->assertSame('https://dyr.test/yrapi.php/index/check', $sent['url']);
        $this->assertSame('A0001', $sent['payload']['out_trade_nums']);
        $this->assertSame(DayuanrenDriver::sign($sent['payload'], 'dyr_key'), $sent['payload']['sign']);

        // 出错、单号对不上、没有状态：处理中
        foreach ([
            ['errno' => '1', 'errmsg' => '订单不存在'],
            ['errno' => '0', 'errmsg' => '查询成功', 'data' => [['out_trade_num' => 'OTHER', 'state' => '1']]],
            ['errno' => '0', 'errmsg' => '查询成功', 'data' => ['out_trade_num' => 'A0001']],
        ] as $body) {
            $this->respond($body);
            $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status, json_encode($body));
        }
    }

    public function testUpstreamProductsAndBalance()
    {
        $this->respond(['errno' => '0', 'errmsg' => 'ok', 'data' => [
            ['id' => 10, 'cate' => '话费快充', 'type' => '1', 'products' => [
                ['id' => '68', 'name' => '移动100', 'desc' => '24小时到账', 'api_open' => '1', 'isp' => '1', 'price' => '98.60', 'ys_tag' => ''],
                ['id' => 69, 'name' => '电信联通50', 'desc' => '', 'api_open' => '0', 'isp' => '2,3', 'price' => '49.40'],
            ]],
        ]]);
        $products = array_map(static fn ($p) => $p->toArray(), $this->driver()->upstreamProducts(self::CONFIG));
        $this->assertSame('https://dyr.test/yrapi.php/index/product', $this->http->requests[0]['url']);
        $this->assertSame(
            ['code' => '68', 'name' => '移动100', 'price' => '98.60', 'face_value' => null, 'operators' => ['cmcc'], 'on_sale' => true, 'note' => '话费快充 · 24小时到账'],
            $products[0]
        );
        $this->assertSame(['cucc', 'ctcc'], $products[1]['operators'], '2 电信、3 联通');
        $this->assertFalse($products[1]['on_sale']);

        $this->respond(['errno' => '0', 'errmsg' => 'ok', 'data' => ['id' => '10001', 'username' => 'pb', 'balance' => '1000.50']]);
        $this->assertSame('1000.50', $this->driver()->balance(self::CONFIG));
        $this->assertSame('https://dyr.test/yrapi.php/index/user', $this->http->requests[1]['url']);
        $this->assertSame(['userid', 'sign'], array_keys($this->http->requests[1]['payload']));

        $this->respond(['errno' => '1', 'errmsg' => '签名错误']);
        $this->expectException(RuntimeException::class);
        $this->driver()->balance(self::CONFIG);
    }

    public function testCallbackVerifiesSignatureAndCompletesOrder()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['errno' => '0', 'errmsg' => '下单成功', 'data' => ['order_number' => 'CZH000001']]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame('CZH000001', $attempt->supplier_order_no);

        // 签名不对、apikey 不对、userid 不对：拒绝，订单不变
        $forged = $this->callbackParams($attempt->attempt_no, 1, $mobile);
        $forged['sign'] = str_repeat('0', 32);
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $forged));
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 1, $mobile, [], 'wrong')));
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 1, $mobile, ['userid' => '99999'])));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 部分成功：保持处理中
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 3, $mobile)));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 文档外的新字段也参与验签
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 1, $mobile, ['new_field' => 'x'])));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
    }

    public function testCallbackFailureFailsOrder()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['errno' => '0', 'errmsg' => '下单成功', 'data' => ['order_number' => 'CZH000002']]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 2, $mobile, ['remark' => '号码已停机'])));
        $attempt = OrderAttempt::find($attempt->id);
        $this->assertSame(OrderAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('号码已停机', $attempt->message);

        // 回调后推的重新提交任务：这个省份只有这一家，订单直接失败退款
        $this->process()->submit($order->id);
        $this->assertSame(Order::STATUS_FAILED, Order::find($order->id)->status);
        $this->assertSame('200.00', Merchant::find($merchant->id)->balance);
    }

    /**
     * @return array{0: Merchant, 1: Product, 2: string, 3: Supplier}
     */
    private function scenario(): array
    {
        $supplier = $this->createSupplier(['广东']);
        $supplier->update(['driver' => 'dayuanren', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
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
     * 照文档字段造一份回调，$extra 覆盖或追加字段，全部参与签名。
     *
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function callbackParams(string $attemptNo, int $state, string $mobile, array $extra = [], string $apikey = 'dyr_key'): array
    {
        $params = $extra + [
            'userid' => '10001',
            'order_number' => 'CZH000001',
            'out_trade_num' => $attemptNo,
            'otime' => (string) time(),
            'state' => (string) $state,
            'mobile' => $mobile,
            'remark' => '',
            'charge_amount' => '100',
            'voucher' => 'http://www.abc.com/xxx',
            'charge_kami' => '',
        ];
        $params['sign'] = DayuanrenDriver::sign($params, $apikey);

        return $params;
    }

    /**
     * 回调是表单 POST。
     *
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
        return new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', '68', self::CONFIG);
    }

    private function driver(): DayuanrenDriver
    {
        return ApplicationContext::getContainer()->get(DayuanrenDriver::class);
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
