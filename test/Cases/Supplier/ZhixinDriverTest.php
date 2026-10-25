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
use App\Supplier\Driver\ZhixinDriver;
use App\Supplier\RechargeRequest;
use App\Supplier\RechargeStatus;
use DateTimeImmutable;
use DateTimeZone;
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
 * 智信科技驱动：JSON + 升序 key/value md5 签名、下单、查单、余额、GET 回调（docs/智信科技平台API接口文档.md 最后一节）。
 *
 * @internal
 * @coversNothing
 */
class ZhixinDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const ATTEMPT_NO = '202610251030150000000101';

    private const CONFIG = [
        'api_url' => 'http://zx.test/',
        'query_url' => '',
        'mrch_no' => '100012',
        'secret_key' => 'zx_key',
        'notify_url' => 'https://pb.test/notify/supplier/zx',
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

    public function testSignJoinsSortedKeyValuesThenSecret()
    {
        // 文档 4.1 的签名字符串：mrch_no100012request_time20160224103037商户密钥
        $this->assertSame(md5('mrch_no100012request_time20160224103037k'), ZhixinDriver::sign(['request_time' => '20160224103037', 'mrch_no' => '100012', 'sign' => 'x'], 'k'));
        // 空值也参与（只拼字段名），整数按字符串拼
        $this->assertSame(
            md5('city_codeclient_order_noA1product_type1k'),
            ZhixinDriver::sign(['product_type' => 1, 'client_order_no' => 'A1', 'city_code' => ''], 'k')
        );
        // 回调：只取文档列出的字段，desc、channel_type、sign 不参与；没收到的字段不拼
        $this->assertSame(
            md5('client_order_noA1deduction_amount98.5000phone_no13800138000product_type1recharge_status2up_order_noU1k'),
            ZhixinDriver::callbackSign([
                'up_order_no' => 'U1', 'recharge_status' => '2', 'product_type' => '1', 'phone_no' => '13800138000',
                'deduction_amount' => '98.5000', 'client_order_no' => 'A1', 'desc' => '成功', 'channel_type' => 'kc', 'sign' => 'x',
            ], 'k')
        );
    }

    public function testOrderTimeComesFromAttemptNo()
    {
        $this->assertSame('20261025103015', ZhixinDriver::orderTime(self::ATTEMPT_NO));
        // 取不到合法的下单时间：用当前北京时间
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('YmdH');
        foreach (['A0001', '20261399999999000001', ''] as $attemptNo) {
            $this->assertStringStartsWith($now, ZhixinDriver::orderTime($attemptNo), $attemptNo);
        }
    }

    public function testRechargeSendsSignedJsonAndAcceptsTwo()
    {
        $this->respond(['code' => '2', 'data' => ['up_order_no' => 'U20260001'], 'message' => '操作成功']);
        $result = $this->driver()->recharge($this->request('ctcc'));

        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertFalse($result->callFailed);
        $this->assertSame('U20260001', $result->supplierOrderNo);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_JSON', $sent['method']);
        $this->assertSame('http://zx.test/zxpaycore/v2/recharge', $sent['url']);
        $payload = $sent['payload'];
        $this->assertSame('100012', $payload['mrch_no']);
        $this->assertSame(self::ATTEMPT_NO, $payload['client_order_no']);
        $this->assertSame(1, $payload['product_type']);
        $this->assertSame('13800138000', $payload['phone_no']);
        $this->assertSame('02', $payload['cp']);
        $this->assertSame('', $payload['city_code']);
        $this->assertSame(10000, $payload['recharge_amount'], '面值按分传');
        $this->assertSame(0, $payload['recharge_type']);
        $this->assertSame('', $payload['recharge_desc']);
        $this->assertSame(self::CONFIG['notify_url'], $payload['notify_url']);
        $this->assertArrayNotHasKey('max_price', $payload);
        $this->assertMatchesRegularExpression('/^\d{14}$/', $payload['request_time']);
        $this->assertSame(md5(
            'city_codeclient_order_no' . self::ATTEMPT_NO . 'cp02mrch_no100012notify_url' . self::CONFIG['notify_url']
            . 'phone_no13800138000product_type1recharge_amount10000recharge_descrecharge_type0request_time' . $payload['request_time'] . 'zx_key'
        ), $payload['sign']);
        $this->assertNotContains('zx_key', $payload);

        // 运营商编码：移动 01、联通 03、广电 04
        foreach (['cmcc' => '01', 'cucc' => '03', 'cbn' => '04'] as $operator => $cp) {
            $this->driver()->recharge($this->request($operator));
            $this->assertSame($cp, $this->http->requests[array_key_last($this->http->requests)]['payload']['cp']);
        }
    }

    public function testRechargeFailsOnAnyOtherCode()
    {
        // 只有 2 算受理：文档列出的失败码、625 重复订单号、表外的码，一律失败换下一家
        foreach (['600', '602', '603', '606', '615', '622', '623', '624', '625', '637', '751', '1', '999'] as $code) {
            $this->respond(['code' => $code, 'message' => '']);
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Failed, $result->status, "code {$code}");
            $this->assertFalse($result->callFailed, "code {$code}");
            $this->assertStringContainsString("[{$code}]", $result->message);
        }
        $this->assertStringContainsString('[999] ', $result->message);
        $this->respond(['code' => 625, 'message' => '']);
        $this->assertStringContainsString('[625] 重复订单号', $this->driver()->recharge($this->request())->message);
        $this->respond(['code' => '623', 'message' => '通道维护中']);
        $this->assertStringContainsString('[623] 通道维护中', $this->driver()->recharge($this->request())->message);

        // 没有响应体、返回无法解析、没有 code、HTTP 出错：没有拿到返回值，转异常人工处理
        foreach ([
            ['status' => 200, 'body' => ''],
            ['status' => 200, 'body' => '<xml>2</xml>'],
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

    public function testQueryMapsRechargeStatus()
    {
        $this->respond(['code' => '2', 'data' => $this->queryData('2'), 'message' => '操作成功']);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Success, $result->status);
        $this->assertSame('U20260001', $result->supplierOrderNo);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_JSON', $sent['method']);
        $this->assertSame('http://zx.test/zxpaycore/v2/query', $sent['url']);
        $payload = $sent['payload'];
        $this->assertSame(['mrch_no', 'request_time', 'client_order_no', 'order_time', 'sign'], array_keys($payload));
        $this->assertSame('20261025103015', $payload['order_time']);
        $this->assertSame(md5(
            'client_order_no' . self::ATTEMPT_NO . 'mrch_no100012order_time20261025103015request_time' . $payload['request_time'] . 'zx_key'
        ), $payload['sign']);

        $this->respond(['code' => '2', 'data' => $this->queryData('6', ['desc' => '业务禁用'])]);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Failed, $result->status);
        $this->assertSame('业务禁用', $result->message);

        $this->respond(['code' => '2', 'data' => $this->queryData('1')]);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertNull($result->message);

        // 626 订单不存在：只记录，订单状态不动
        $this->respond(['code' => '626', 'message' => '订单不存在']);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertFalse($result->callFailed);
        $this->assertStringContainsString('只记录', $result->message);

        // 603 查询异常、606、751、表外的码和状态、订单号对不上、没拿到返回：不处理订单
        foreach ([
            ['code' => '603', 'message' => '请求数据不正确'],
            ['code' => '606', 'message' => ''],
            ['code' => '751', 'message' => ''],
            ['code' => '2', 'data' => $this->queryData('9')],
            ['code' => '2', 'data' => $this->queryData('2', ['client_order_no' => 'OTHER'])],
            ['code' => '2', 'data' => null],
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

    public function testQueryAndBalanceUseQueryUrlWhenConfigured()
    {
        $config = ['query_url' => 'http://zx-query.test'] + self::CONFIG;
        $this->respond(['code' => '2', 'data' => $this->queryData('1')]);
        $this->driver()->query(new RechargeRequest(self::ATTEMPT_NO, '13800138000', 100, 'cmcc', '广东', 'ext', $config));
        $this->assertSame('http://zx-query.test/zxpaycore/v2/query', $this->http->requests[0]['url']);

        $this->respond(['code' => '2', 'data' => ['balance' => '1.0000']]);
        $this->driver()->balance($config);
        $this->assertSame('http://zx-query.test/zxpaycore/v2/balance', $this->http->requests[1]['url']);

        // 下单仍走接口域名
        $this->respond(['code' => '2', 'data' => ['up_order_no' => 'U1']]);
        $this->driver()->recharge(new RechargeRequest(self::ATTEMPT_NO, '13800138000', 100, 'cmcc', '广东', 'ext', $config));
        $this->assertSame('http://zx.test/zxpaycore/v2/recharge', $this->http->requests[2]['url']);
    }

    public function testBalance()
    {
        $this->respond(['code' => '2', 'data' => ['balance' => '45984.3000'], 'message' => '查询商户余额成功!']);
        $this->assertSame('45984.3000', $this->driver()->balance(self::CONFIG));
        $sent = $this->http->requests[0];
        $this->assertSame('POST_JSON', $sent['method']);
        $this->assertSame('http://zx.test/zxpaycore/v2/balance', $sent['url']);
        $this->assertSame(['mrch_no', 'request_time', 'sign'], array_keys($sent['payload']));
        $this->assertSame(md5('mrch_no100012request_time' . $sent['payload']['request_time'] . 'zx_key'), $sent['payload']['sign']);

        foreach ([['code' => '606', 'message' => '数据签名错误'], ['code' => '2', 'data' => []], ['message' => 'x']] as $body) {
            $this->respond($body);
            try {
                $this->driver()->balance(self::CONFIG);
                $this->fail('查询失败应抛异常：' . json_encode($body));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('查询余额失败', $e->getMessage());
            }
        }
    }

    public function testCallbackVerifiesSignatureAndCompletesOrder()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['code' => '2', 'data' => ['up_order_no' => 'U20260001'], 'message' => '操作成功']);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame($attempt->attempt_no, $this->http->requests[0]['payload']['client_order_no']);
        $this->assertSame(self::CONFIG['notify_url'], $this->http->requests[0]['payload']['notify_url']);
        $this->assertSame('U20260001', $attempt->supplier_order_no);

        // 签名不对、密钥不对：拒绝，订单不变
        $forged = $this->callbackParams($attempt->attempt_no, '2', $mobile);
        $forged['sign'] = str_repeat('0', 32);
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $forged));
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, '2', $mobile, [], 'wrong')));
        // 改了参与签名的状态：验签不过
        $tampered = $this->callbackParams($attempt->attempt_no, '6', $mobile);
        $tampered['recharge_status'] = '2';
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $tampered));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 其他 recharge_status：交易中，回 SUCCESS 但不处理订单
        $this->assertSame([200, 'SUCCESS'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, '1', $mobile)));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // desc、channel_type 不参与签名，改了也照样通过；带 elecardID 时参与签名
        $params = $this->callbackParams($attempt->attempt_no, '2', $mobile, ['elecardID' => 'E123']);
        $params['desc'] = '充值成功';
        $params['channel_type'] = 'kc';
        $this->assertSame([200, 'SUCCESS'], $this->supplierCallback($supplier, $params));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
    }

    public function testCallbackFailureFailsOrder()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['code' => '2', 'data' => ['up_order_no' => 'U20260001']]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        $this->assertSame([200, 'SUCCESS'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, '6', $mobile, ['desc' => '欠费/停机'])));
        $attempt = OrderAttempt::find($attempt->id);
        $this->assertSame(OrderAttempt::STATUS_FAILED, $attempt->status);
        $this->assertStringContainsString('欠费/停机', $attempt->message);

        // 回调后推的重新提交任务：这个省份只有这一家，订单直接失败退款
        $this->process()->submit($order->id);
        $this->assertSame(Order::STATUS_FAILED, Order::find($order->id)->status);
        $this->assertSame('200.00', Merchant::find($merchant->id)->balance);
    }

    public function testRejectedSubmitFailsOverAndNoReplyMarksOrderAbnormal()
    {
        [$merchant, $product, $mobile] = $this->scenario();

        // 625 重复订单号：上游有返回，判失败；只有这一家，订单失败退款
        $this->respond(['code' => '625', 'message' => '重复订单号']);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $this->assertSame(Order::STATUS_FAILED, Order::find($order->id)->status);
        $this->assertSame('200.00', Merchant::find($merchant->id)->balance);

        // 没拿到返回：转异常人工处理，不退款、不换下一家
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
        $supplier->update(['driver' => 'zhixin', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
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
     * @return array<string, mixed>
     */
    private function queryData(string $status, array $extra = []): array
    {
        return $extra + [
            'client_order_no' => self::ATTEMPT_NO,
            'up_order_no' => 'U20260001',
            'product_type' => '1',
            'phone_no' => '13800138000',
            'deduction_amount' => '9850.0000',
            'recharge_status' => $status,
        ];
    }

    /**
     * 照文档 5.5 造一份回调，$extra 覆盖或追加字段；签名只算文档列出的字段。
     *
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function callbackParams(string $attemptNo, string $status, string $mobile, array $extra = [], string $key = 'zx_key'): array
    {
        $params = $extra + [
            'client_order_no' => $attemptNo,
            'up_order_no' => 'U20260001',
            'product_type' => '1',
            'phone_no' => $mobile,
            'deduction_amount' => '9850.0000',
            'recharge_status' => $status,
        ];
        $params['sign'] = ZhixinDriver::callbackSign($params, $key);

        return $params;
    }

    /**
     * 回调是 GET，参数在查询字符串里。
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: string}
     */
    private function supplierCallback(Supplier $supplier, array $params): array
    {
        $response = $this->client->request('GET', "/notify/supplier/{$supplier->code}", ['query' => $params]);

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    private function request(string $operator = 'cmcc'): RechargeRequest
    {
        return new RechargeRequest(self::ATTEMPT_NO, '13800138000', 100, $operator, '广东', 'ext', self::CONFIG);
    }

    private function driver(): ZhixinDriver
    {
        return ApplicationContext::getContainer()->get(ZhixinDriver::class);
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
