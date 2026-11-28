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
use App\Supplier\Driver\KasushouDriver;
use App\Supplier\RechargeRequest;
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
 * 卡速售驱动：appKey + timestamp + keyvalue 的 md5 签名、下单、余额、商品列表、带 token 的回调（docs/卡速售.md 最后一节）。
 *
 * @internal
 * @coversNothing
 */
class KasushouDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const CONFIG = [
        'api_url' => 'https://kss.test/',
        'app_id' => 'kss_app',
        'app_key' => 'kss_key',
        'user_id' => '15',
        'category_ids' => '34,35',
        'notify_url' => 'https://pb.test/notify/supplier/kss',
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
        $this->assertInstanceOf(KasushouDriver::class, make(SupplierDriverRegistry::class)->find('kasushou'));
    }

    public function testSignMatchesDocument()
    {
        // 文档各接口请求示例里的签名
        $data = [
            'productId' => 31, 'number' => 17636618110, 'buyNum' => 1, 'apiOrderSn' => '20251114',
            'callbackUrl' => 'https://605d71f4.r5.cpolar.top/module-api/external/product/getProductInfo', 'api' => 'api',
        ];
        $this->assertSame('cb109f0bc4c93be8b85985a9cbc9bd1e', KasushouDriver::sign($data, 'e61384f551704865b11edb41d0a97d42', 1763974149213));
        $key = '199761066f8b4d04b8b5c06fcf0523ea';
        $this->assertSame('2b45e1fd8a3a2ca9462d435669abef86', KasushouDriver::sign(['pageNum' => 1, 'pageSize' => 10, 'productName' => null, 'productSn' => null, 'status' => null], $key, 1764929609790));
        $this->assertSame('1134f62fb46a2f37fece9212e31a8dcc', KasushouDriver::sign(['id' => 1], $key, 1764929681853));
        $this->assertSame('2f0811f6671e7e8912b27af494b50819', KasushouDriver::sign(['categoryName' => null], $key, 1764929783081));
        $this->assertSame('75d485640f464961c402b601b10add4c', KasushouDriver::sign(['userId' => 15, 'categoryIds' => null], $key, '1764930066088'));
        // 空串不参与，数字 0 参与
        $this->assertSame(md5('k1a0b1'), KasushouDriver::sign(['b' => '1', 'a' => 0, 'c' => ''], 'k', 1));
    }

    public function testRechargeSendsSignedJsonAndMapsReplies()
    {
        $this->respond($this->reply(200, ['success' => true, 'orderNo' => '20251124164921668611', 'message' => '下单成功', 'orderId' => 34668, 'apiOrderSn' => 'A0001'], '下单成功'));
        $result = $this->driver()->recharge($this->request());
        $this->assertSame([RechargeStatus::Processing, false, '20251124164921668611'], [$result->status, $result->callFailed, $result->supplierOrderNo]);
        $sent = $this->http->requests[0];
        $this->assertSame(['POST_JSON', 'https://kss.test/prod-api/module-api/external/order/createOrder'], [$sent['method'], $sent['url']]);
        $payload = $sent['payload'];
        $data = (array) $payload['data'];
        $callbackUrl = self::CONFIG['notify_url'] . '?token=' . KasushouDriver::token('A0001', 'kss_key');
        $this->assertSame(['productId' => 31, 'number' => 13800138000, 'buyNum' => 1, 'apiOrderSn' => 'A0001', 'callbackUrl' => $callbackUrl, 'api' => 'api'], $data);
        $this->assertSame(['kss_app', 'kss_key'], [$payload['appId'], $payload['appKey']]);
        $this->assertSame(13, strlen((string) $payload['timestamp']), '13 位毫秒时间戳');
        $this->assertSame(KasushouDriver::sign($data, 'kss_key', $payload['timestamp']), $payload['sign']);
        $logged = json_decode((string) $result->request, true);
        $this->assertSame('A0001', $logged['data']['apiOrderSn'], '记下发出的参数');
        $this->assertArrayNotHasKey('appKey', $logged, '原始报文里不记 appKey');
        $this->assertArrayNotHasKey('sign', $logged);

        // 明确说没成功、其他 code：判失败换下一家
        $this->respond($this->reply(200, ['success' => false, 'message' => '商品已下架']));
        $result = $this->driver()->recharge($this->request());
        $this->assertSame([RechargeStatus::Failed, '上游下单失败：商品已下架'], [$result->status, $result->message]);
        foreach ([500 => '余额不足', 401 => '签名错误'] as $code => $message) {
            $this->respond($this->reply($code, null, $message));
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Failed, $result->status, (string) $code);
            $this->assertStringContainsString("[{$code}] {$message}", (string) $result->message);
        }
        // 没拿到返回值、code=200 却没有 data.success：不知道建没建单，转人工
        foreach ([[502, '<html>Bad Gateway</html>'], [200, '{"msg":"x"}'], [200, (string) json_encode($this->reply(200, null, '下单成功'))]] as [$status, $body]) {
            $this->http->responses = [['status' => $status, 'body' => $body]];
            $result = $this->driver()->recharge($this->request());
            $this->assertSame([RechargeStatus::Processing, true], [$result->status, $result->callFailed], $body);
        }
        // 连不上：带着请求报文报调用异常
        $this->http->responses = [['exception' => 'cURL error 28: timed out']];
        try {
            $this->driver()->recharge($this->request());
            $this->fail('应该抛异常');
        } catch (UnclearResultException $e) {
            $this->assertTrue($e->result->callFailed);
            $this->assertSame('A0001', json_decode((string) $e->result->request, true)['data']['apiOrderSn']);
        }
    }

    public function testQueryDoesNotCallUpstream()
    {
        $result = $this->driver()->query(new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', '31', self::CONFIG, 'S1'));
        $this->assertSame([RechargeStatus::Processing, false, false, 'S1'], [$result->status, $result->callFailed, $result->notFound, $result->supplierOrderNo]);
        $this->assertStringContainsString('没有查单接口', (string) $result->message);
        $this->assertSame([], $this->http->requests);
    }

    public function testBalanceAndProducts()
    {
        $this->respond($this->reply(200, '772333.00', '操作成功'));
        $this->assertSame('772333.00', $this->driver()->balance(self::CONFIG));
        $sent = $this->http->requests[0];
        $this->assertSame('https://kss.test/prod-api/module-api/external/userCategoryBalance/balance', $sent['url']);
        $this->assertSame(['userId' => 15, 'categoryIds' => '34,35'], (array) $sent['payload']['data']);

        // 不填分类不发 categoryIds
        $this->http->requests = [];
        $this->respond($this->reply(200, '1.5'));
        $this->assertSame('1.5', $this->driver()->balance(['category_ids' => ''] + self::CONFIG));
        $this->assertSame(['userId' => 15], (array) $this->http->requests[0]['payload']['data']);

        // 两页取完
        $this->http->requests = [];
        $rows = array_map(fn (int $i) => $this->product($i, "官方快充-移动{$i}元"), range(1, 100));
        $this->http->responses = [
            ['status' => 200, 'body' => (string) json_encode(['total' => 102, 'rows' => $rows, 'code' => 200, 'msg' => '查询成功'], JSON_UNESCAPED_UNICODE)],
            ['status' => 200, 'body' => (string) json_encode(['total' => 102, 'rows' => [
                $this->product(101, '框架直充-联通100元', ['denomination' => '100.00', 'price' => '96.50', 'categoryName' => '框架直充', 'productSn' => 'LX0101']),
                $this->product(102, '电信50', ['status' => '0']),
            ], 'code' => 200, 'msg' => '查询成功'], JSON_UNESCAPED_UNICODE)],
        ];
        $products = array_map(static fn ($p) => $p->toArray(), $this->driver()->upstreamProducts(self::CONFIG));
        $this->assertCount(102, $products);
        $this->assertSame(['code' => '1', 'name' => '官方快充-移动1元', 'price' => '50.00', 'face_value' => 50, 'operators' => ['cmcc'], 'on_sale' => true, 'note' => '官方快充 / LX0001'], $products[0]);
        $this->assertSame(['code' => '101', 'name' => '框架直充-联通100元', 'price' => '96.50', 'face_value' => 100, 'operators' => ['cucc'], 'on_sale' => true, 'note' => '框架直充 / LX0101'], $products[100]);
        $this->assertSame([['ctcc'], false], [$products[101]['operators'], $products[101]['on_sale']], '下架');
        $this->assertSame('https://kss.test/prod-api/module-api/external/product/pageList', $this->http->requests[0]['url']);
        $this->assertSame([['pageNum' => 1, 'pageSize' => 100], ['pageNum' => 2, 'pageSize' => 100]], array_map(static fn ($r) => (array) $r['payload']['data'], $this->http->requests));

        $this->respond(['total' => 0, 'rows' => [], 'code' => 200, 'msg' => '查询成功']);
        $this->assertSame([], $this->driver()->upstreamProducts(self::CONFIG));

        $this->respond($this->reply(500, null, '签名错误'));
        try {
            $this->driver()->upstreamProducts(self::CONFIG);
            $this->fail('应该抛异常');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('[500] 签名错误', $e->getMessage());
        }

        // 没填用户 ID：查不了余额，也不发请求
        $this->http->requests = [];
        $this->expectException(RuntimeException::class);
        try {
            $this->driver()->balance(['user_id' => ''] + self::CONFIG);
        } finally {
            $this->assertSame([], $this->http->requests);
        }
    }

    public function testCallbackNeedsTokenAndIsApplied()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();
        $this->respond($this->reply(200, ['success' => true, 'orderNo' => 'K20251124001']));
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame([Order::STATUS_PROCESSING, 'K20251124001'], [Order::find($order->id)->status, $attempt->supplier_order_no]);
        $sent = (array) $this->http->requests[0]['payload']['data'];
        $this->assertSame([31, KasushouDriver::callbackUrl(self::CONFIG['notify_url'], $attempt->attempt_no, 'kss_key')], [$sent['productId'], $sent['callbackUrl']]);

        // 没带 token、token 不对、拿别的单的 token：拒绝，订单不变
        $params = $this->callbackParams($attempt->attempt_no, '3');
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $params));
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $params, 'bad'));
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $params, KasushouDriver::token('OTHER', 'kss_key')));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        $token = KasushouDriver::token($attempt->attempt_no, 'kss_key');
        // 正在充值：照常应答，订单不变
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, '5'), $token));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 已完成：订单成功
        $this->http->requests = [];
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $params, $token));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
        $this->assertSame([], $this->http->requests);
    }

    public function testCancelledCallbackMovesToNextSupplier()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();
        $this->respond($this->reply(200, ['success' => true, 'orderNo' => 'K20251124002']));
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, '4'), KasushouDriver::token($attempt->attempt_no, 'kss_key')));
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
        $supplier->update(['driver' => 'kasushou', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
        $supplierProduct = $this->createSupplierProduct($supplier, ['cmcc'], 100, '98.80');
        $supplierProduct->update(['external_code' => '31']);
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
     * 照文档「回调参数文档」的字段造一份回调。
     *
     * @return array<string, mixed>
     */
    private function callbackParams(string $attemptNo, string $orderStatus): array
    {
        return ['orderSn' => 'K20251124001', 'apiOrderSn' => $attemptNo, 'orderPrice' => 98.8, 'orderStatus' => $orderStatus];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: int, 1: string}
     */
    private function supplierCallback(Supplier $supplier, array $params, ?string $token = null): array
    {
        $path = "/notify/supplier/{$supplier->code}" . ($token === null ? '' : '?token=' . $token);
        $response = $this->client->request('POST', $path, ['json' => $params, 'headers' => ['Content-Type' => 'application/json']]);

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function product(int $id, string $name, array $override = []): array
    {
        return $override + [
            'id' => $id, 'productSn' => sprintf('LX%04d', $id), 'productName' => $name, 'categoryName' => '官方快充', 'productType' => '1',
            'brandName' => null, 'carrier' => '1', 'price' => '50.00', 'denomination' => '50.00', 'productBrief' => null, 'mainImage' => null,
            'sortOrder' => 0, 'sourceType' => '2', 'status' => '1', 'stock' => 99999999,
        ];
    }

    private function reply(int $code, mixed $data, string $msg = '操作成功'): array
    {
        return ['code' => $code, 'msg' => $msg, 'data' => $data];
    }

    private function request(): RechargeRequest
    {
        return new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', '31', self::CONFIG);
    }

    private function driver(): KasushouDriver
    {
        return ApplicationContext::getContainer()->get(KasushouDriver::class);
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
