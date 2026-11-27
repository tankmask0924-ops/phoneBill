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
use App\Supplier\Driver\JideshengDriver;
use App\Supplier\RechargeRequest;
use App\Supplier\RechargeResult;
use App\Supplier\RechargeStatus;
use App\Supplier\SupplierDriverRegistry;
use App\Supplier\UnclearResultException;
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
 * 记得省驱动：k=v& 拼接的大写 md5 签名、下单、查单、余额、商品列表、回调验签（docs/记得省.md 最后一节）。
 *
 * @internal
 * @coversNothing
 */
class JideshengDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const CONFIG = [
        'api_url' => 'https://jds.test/',
        'user_no' => 'A000012',
        'secret_key' => 'jds_secret',
        'notify_url' => 'https://pb.test/notify/supplier/jds',
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

    public function testRegistered()
    {
        $this->assertInstanceOf(JideshengDriver::class, make(SupplierDriverRegistry::class)->find('jidesheng'));
    }

    public function testSignMatchesDocument()
    {
        // 文档「签名」一节的示例
        $this->assertSame('715022C7544A5D7799DA05F236BF79DF', JideshengDriver::sign(['timestamp' => 1730000000000, 'userNo' => 'A10001', 'sign' => 'x'], 'demoSecretKey'));
        // 去掉 null 和空串，数字 0 参与；多个值用英文逗号；整数形式的小数不带小数点
        $this->assertSame(
            strtoupper(md5('a=0&carrier=1,2&price=46.1&total=50&userNo=U&secretKey=k')),
            JideshengDriver::sign(['userNo' => 'U', 'carrier' => ['1', '2'], 'a' => 0, 'empty' => '', 'none' => null, 'price' => 46.1, 'total' => 50.0], 'k'),
        );
    }

    public function testRechargeSendsSignedJsonAndMapsReplies()
    {
        $this->respond($this->reply(0, ['orderNo' => 'C2601091720', 'clientOrderNo' => 'A0001', 'totalAmount' => 46.2]));
        $result = $this->driver()->recharge($this->request());
        $this->assertSame([RechargeStatus::Processing, false, 'C2601091720'], [$result->status, $result->callFailed, $result->supplierOrderNo]);
        $sent = $this->http->requests[0];
        $this->assertSame(['POST_JSON', 'https://jds.test/open/v1/checkout'], [$sent['method'], $sent['url']]);
        $payload = $sent['payload'];
        $this->assertSame(['userNo' => 'A000012', 'productId' => 'P1', 'phone' => '13800138000', 'clientOrderNo' => 'A0001', 'notifyUrl' => self::CONFIG['notify_url']], array_diff_key($payload, ['timestamp' => 1, 'sign' => 1]));
        $this->assertSame(13, strlen((string) $payload['timestamp']), '13 位毫秒时间戳');
        $this->assertSame(JideshengDriver::sign($payload, 'jds_secret'), $payload['sign']);
        $this->assertSame($payload['clientOrderNo'], json_decode((string) $result->request, true)['clientOrderNo'], '记下发出的参数（不含签名）');
        $this->assertArrayNotHasKey('sign', json_decode((string) $result->request, true));

        // 3001 重复提交：上游已有这笔单，按处理中，不判失败
        $this->respond($this->reply(3001, null, '重复提交'));
        $result = $this->driver()->recharge($this->request());
        $this->assertSame([RechargeStatus::Processing, false], [$result->status, $result->callFailed]);
        // 9000 服务错误、没拿到返回值：不知道建没建单，转人工
        foreach ([[200, (string) json_encode($this->reply(9000, null, '服务错误'))], [502, '<html>Bad Gateway</html>'], [200, '{"message":"x"}']] as [$status, $body]) {
            $this->http->responses = [['status' => $status, 'body' => $body]];
            $result = $this->driver()->recharge($this->request());
            $this->assertSame([RechargeStatus::Processing, true], [$result->status, $result->callFailed], $body);
        }
        // 其他错误码：判失败换下一家
        foreach ([3003 => '余额不足', 3002 => '产品已下架', 1006 => 'IP 不在白名单内', 2002 => '运营商不匹配'] as $code => $message) {
            $this->respond($this->reply($code, null, $message));
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Failed, $result->status, (string) $code);
            $this->assertStringContainsString("[{$code}] {$message}", (string) $result->message);
        }
        // 连不上：带着请求报文报调用异常
        $this->http->responses = [['exception' => 'cURL error 28: timed out']];
        try {
            $this->driver()->recharge($this->request());
            $this->fail('应该抛异常');
        } catch (UnclearResultException $e) {
            $this->assertTrue($e->result->callFailed);
            $this->assertSame('A0001', json_decode((string) $e->result->request, true)['clientOrderNo']);
        }
    }

    public function testQueryMapsStatuses()
    {
        foreach (['SUCCESS' => RechargeStatus::Success, 'FAILED' => RechargeStatus::Failed, 'CREATED' => RechargeStatus::Processing, 'PROCESSING' => RechargeStatus::Processing, 'PENDING_SUPPLEMENT' => RechargeStatus::Processing] as $status => $expected) {
            $this->http->requests = [];
            $this->respond($this->reply(0, [$this->order($status, 'A0001', $status === 'FAILED' ? '号码停机' : '')]));
            $result = $this->driver()->query($this->request());
            $this->assertSame($expected, $result->status, $status);
            $this->assertSame('C2601091723', $result->supplierOrderNo);
            $this->assertFalse($result->notFound);
        }
        $this->assertSame('上游充值失败：号码停机', $this->queryWith($this->reply(0, [$this->order('FAILED', 'A0001', '号码停机')]))->message);
        $this->assertSame('https://jds.test/open/v1/orders', $this->http->requests[0]['url']);
        $this->assertSame('A0001', $this->http->requests[0]['payload']['clientOrderNo']);

        // 2003 查不到：只记录，标记查无此单
        $result = $this->queryWith($this->reply(2003, null, '未查询到数据'));
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertTrue($result->notFound, '上游明确查不到这笔订单');
        $this->assertStringContainsString('[2003] 未查询到数据', (string) $result->message);

        // 单号对不上、其他报错、无法解析：处理中
        foreach ([$this->reply(0, [$this->order('SUCCESS', 'OTHER')]), $this->reply(9000, null, '服务错误'), $this->reply(1003, null, '请求已过期')] as $body) {
            $result = $this->queryWith($body);
            $this->assertSame([RechargeStatus::Processing, false], [$result->status, $result->notFound]);
            $this->assertStringStartsWith('查单未拿到明确结果', (string) $result->message);
        }
    }

    public function testBalanceAndProducts()
    {
        $this->respond($this->reply(0, ['userNo' => 'A000012', 'status' => 'Activated', 'balance' => 555.7]));
        $this->assertSame('555.7', $this->driver()->balance(self::CONFIG));
        $this->assertSame('https://jds.test/open/v1/accounts', $this->http->requests[0]['url']);

        $this->respond($this->reply(0, [
            ['productId' => '1', 'name' => '三网50元', 'faceValue' => 50, 'unit' => '元', 'price' => 46.2, 'carrier' => ['1', '3'], 'carrierName' => ['移动', '电信'], 'category' => '话费', 'type' => '快充'],
            ['productId' => '2', 'name' => '联通100', 'faceValue' => 100, 'price' => 95, 'carrier' => ['2'], 'category' => '话费', 'type' => ''],
        ]));
        $products = array_map(static fn ($p) => $p->toArray(), $this->driver()->upstreamProducts(self::CONFIG));
        $this->assertSame([
            ['code' => '1', 'name' => '三网50元', 'price' => '46.2', 'face_value' => 50, 'operators' => ['cmcc', 'ctcc'], 'on_sale' => true, 'note' => '话费 / 快充'],
            ['code' => '2', 'name' => '联通100', 'price' => '95', 'face_value' => 100, 'operators' => ['cucc'], 'on_sale' => true, 'note' => '话费'],
        ], $products);

        $this->respond($this->reply(2003, null, '未查询到数据'));
        $this->assertSame([], $this->driver()->upstreamProducts(self::CONFIG));
        $this->respond($this->reply(9000, null, '查询太频繁'));
        $this->expectException(RuntimeException::class);
        $this->driver()->upstreamProducts(self::CONFIG);
    }

    public function testCallbackIsVerifiedAndApplied()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();
        $this->respond($this->reply(0, ['orderNo' => 'C2601091720']));
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame([Order::STATUS_PROCESSING, 'C2601091720'], [Order::find($order->id)->status, $attempt->supplier_order_no]);
        $this->assertSame('ext', $this->http->requests[0]['payload']['productId']);

        // 签名不对、没签名：拒绝，订单不变
        $params = $this->callbackParams($attempt->attempt_no, 'SUCCESS');
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, ['sign' => 'BAD'] + $params));
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $params));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 充值中：照常应答，订单不变
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->signed($this->callbackParams($attempt->attempt_no, 'PROCESSING'))));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 验签通过的成功回调：订单成功，不用再查单
        $this->http->requests = [];
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->signed($params)));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
        $this->assertSame([], $this->http->requests);
    }

    public function testFailedCallbackMovesToNextSupplier()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();
        $this->respond($this->reply(0, ['orderNo' => 'C2601091720']));
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->signed($this->callbackParams($attempt->attempt_no, 'FAILED'))));
        $this->assertSame(OrderAttempt::STATUS_FAILED, OrderAttempt::find($attempt->id)->status);

        // 只有这一家：失败退款
        $this->process()->submit($order->id);
        $this->assertSame(Order::STATUS_FAILED, Order::find($order->id)->status);
    }

    /**
     * @return array{0: Merchant, 1: Product, 2: string, 3: Supplier}
     */
    private function scenario(): array
    {
        $supplier = $this->createSupplier(['广东']);
        $supplier->update(['driver' => 'jidesheng', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
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
     * 照文档字段造一份回调（带数字、空串字段，验签时空串不参与）。
     *
     * @return array<string, mixed>
     */
    private function callbackParams(string $attemptNo, string $orderStatus): array
    {
        return [
            'timestamp' => 1770867881374,
            'userNo' => 'A000012',
            'username' => '演示代理',
            'orderNo' => 'C2601091720',
            'clientOrderNo' => $attemptNo,
            'orderStatus' => $orderStatus,
            'finishTime' => '2026-02-12T03:44:41.344Z',
            'createdAt' => '2026-02-12T03:44:26.465Z',
            'quantity' => 1,
            'totalAmount' => 46.1,
            'productId' => 'ext',
            'productName' => '三网50元',
            'faceValue' => 50,
            'cateName' => '话费',
            'typeName' => '快充',
            'phone' => '13800138000',
            'attribution' => '广东/深圳',
            'carrierName' => '移动',
            'owner' => '',
            'area' => '',
            'city' => '',
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function signed(array $params): array
    {
        return $params + ['sign' => JideshengDriver::sign($params, 'jds_secret')];
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

    /**
     * @return array<string, mixed>
     */
    private function order(string $status, string $clientOrderNo, string $failReason = ''): array
    {
        return [
            'userNo' => 'A000012', 'orderNo' => 'C2601091723', 'clientOrderNo' => $clientOrderNo, 'productId' => 'P1', 'faceValue' => 100,
            'totalAmount' => 95.5, 'phone' => '13800138000', 'orderStatus' => $status, 'failReason' => $failReason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reply(int $code, mixed $data, string $message = 'success'): array
    {
        return ['code' => $code, 'message' => $message, 'data' => $data, 'timestamp' => 1767953564183];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function queryWith(array $body): RechargeResult
    {
        $this->http->requests = [];
        $this->respond($body);

        return $this->driver()->query($this->request());
    }

    private function request(): RechargeRequest
    {
        return new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', 'P1', self::CONFIG);
    }

    private function driver(): JideshengDriver
    {
        return ApplicationContext::getContainer()->get(JideshengDriver::class);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(array $body, int $status = 200): void
    {
        $this->http->responses = [['status' => $status, 'body' => (string) json_encode($body, JSON_UNESCAPED_UNICODE)]];
    }

    private function process(): OrderProcessService
    {
        return ApplicationContext::getContainer()->get(OrderProcessService::class);
    }
}
