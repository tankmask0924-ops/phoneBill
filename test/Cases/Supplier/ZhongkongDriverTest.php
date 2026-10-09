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
use App\Supplier\Driver\ZhongkongDriver;
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
class ZhongkongDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const CONFIG = [
        'recharge_url' => 'https://zk.test/api/recharge',
        'query_url' => 'https://zk.test/api/query',
        'balance_url' => 'https://zk.test/api/balance',
        'appid' => 'zk_app',
        'secret' => 'zk_secret',
        'timestamp_format' => 'datetime',
        'notify_url' => 'https://pb.test/notify/supplier/zk',
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
        $this->assertSame(md5('a=1&b=2&key=xx'), ZhongkongDriver::sign(['b' => '2', 'a' => '1', 'sign' => 'ignored'], 'xx'));
    }

    public function testRechargeSendsSignedJsonAndOnlyTrustsAcceptance()
    {
        $this->respond(['code' => '0', 'msg' => 'success']);
        $result = $this->driver()->recharge($this->request());

        $this->assertSame(RechargeStatus::Processing, $result->status);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_JSON', $sent['method']);
        $this->assertSame(self::CONFIG['recharge_url'], $sent['url']);
        $payload = $sent['payload'];
        $this->assertSame('A0001', $payload['inOrderNumber']);
        $this->assertSame('zk_app', $payload['appid']);
        $this->assertSame('4028b8815f7d4ae2015f86ee6aa70009', $payload['agentProductId']);
        $this->assertSame('13800138000', $payload['phone']);
        $this->assertSame(self::CONFIG['notify_url'], $payload['notifyUrl']);
        $this->assertSame('1', $payload['num']);
        $this->assertMatchesRegularExpression('/^\d{14}$/', $payload['timestamp']);
        $this->assertSame(ZhongkongDriver::sign($payload, 'zk_secret'), $payload['sign']);
        $this->assertContainsOnly('string', $payload);

        // 时间戳格式可选秒级时间戳
        $this->http->requests = [];
        $this->driver()->recharge($this->request(['timestamp_format' => 'unix']));
        $this->assertMatchesRegularExpression('/^\d{10}$/', $this->http->requests[0]['payload']['timestamp']);

        // 订单号已存在：之前提交过，处理中
        $this->respond(['code' => '1', 'msg' => '商家订单号已经存在 !']);
        $result = $this->driver()->recharge($this->request());
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertStringContainsString('已存在', $result->message);

        // code=500 不知道建没建单、数字 code、返回无法解析：都是处理中，等查单
        $this->respond(['code' => '500', 'msg' => '产品已下架']);
        $result = $this->driver()->recharge($this->request());
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertStringContainsString('[500] 产品已下架', $result->message);
        $this->respond(['code' => 0, 'msg' => 'success']);
        $this->assertNull($this->driver()->recharge($this->request())->message);
        $this->http->responses = [['status' => 502, 'body' => '<html>Bad Gateway</html>']];
        $this->assertSame(RechargeStatus::Processing, $this->driver()->recharge($this->request())->status);
    }

    public function testQueryMapsStatuses()
    {
        foreach (['3' => RechargeStatus::Success, '4' => RechargeStatus::Failed, '1' => RechargeStatus::Processing, '2' => RechargeStatus::Processing] as $status => $mapped) {
            $this->respond(['code' => '0', 'msg' => 'success', 'status' => (string) $status, 'cards' => '']);
            $this->assertSame($mapped, $this->driver()->query($this->request())->status, "status {$status}");
        }
        $sent = $this->http->requests[0];
        $this->assertSame(self::CONFIG['query_url'], $sent['url']);
        $this->assertSame('A0001', $sent['payload']['inOrderNumber']);
        $this->assertSame(ZhongkongDriver::sign($sent['payload'], 'zk_secret'), $sent['payload']['sign']);

        // 查不到 / 出错、没有状态：处理中
        $this->respond(['code' => '500', 'msg' => '订单不存在']);
        $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status);
        $this->respond(['code' => '0', 'msg' => 'success']);
        $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status);
    }

    public function testBalance()
    {
        $this->respond(['code' => '0', 'message' => '操作成功', 'debtAmount' => '1000.50', 'amount' => '20', 'freezeAmount' => '0', 'qualification' => '0']);
        $this->assertSame('1000.50', $this->driver()->balance(self::CONFIG));
        $this->assertSame(self::CONFIG['balance_url'], $this->http->requests[0]['url']);
        $this->assertSame(['appid', 'timestamp', 'sign'], array_keys($this->http->requests[0]['payload']));

        // 没填余额接口地址：不支持
        $this->assertNull($this->driver()->balance(['balance_url' => ''] + self::CONFIG));

        // 返回里没有 debtAmount：报错，不瞎猜
        $this->respond(['code' => '0', 'message' => '操作成功', 'amount' => '0', 'freezeAmount' => '0', 'qualification' => '0']);
        $this->expectException(RuntimeException::class);
        $this->driver()->balance(self::CONFIG);
    }

    public function testCallbackVerifiesSignatureAndCompletesOrder()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['code' => '0', 'msg' => 'success']);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 签名不对、秘钥不对：拒绝，订单不变
        $forged = $this->callbackParams($attempt->attempt_no, 3, $mobile);
        $forged['sign'] = str_repeat('0', 32);
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $forged));
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 3, $mobile, [], 'wrong')));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 文档写法：param1 / param2 为空时不参与签名，其他字段（含空的 cards）都参与；sign 大写也认
        $params = $this->callbackParams($attempt->attempt_no, 3, $mobile, ['param1' => '', 'param2' => '', 'cards' => '']);
        $params['sign'] = strtoupper($params['sign']);
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $params));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
    }

    public function testCallbackAcceptsSignatureWithoutAnyEmptyFieldAndFailsOrder()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['code' => '0', 'msg' => 'success']);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        // 另一种算法：所有空字段都不参与签名
        $params = $this->callbackParams($attempt->attempt_no, 4, $mobile);
        $params += ['param1' => '', 'param2' => '', 'cards' => ''];
        $this->assertSame([200, 'success'], $this->supplierCallback($supplier, $params));
        $this->assertSame(OrderAttempt::STATUS_FAILED, OrderAttempt::find($attempt->id)->status);

        // 回调后推的重新提交任务：这个省份只有这一家，订单直接失败退款
        $this->process()->submit($order->id);
        $order = Order::find($order->id);
        $this->assertSame(Order::STATUS_FAILED, $order->status);
        $this->assertSame(1, OrderAttempt::where('order_id', $order->id)->count());
        $this->assertSame('200.00', Merchant::find($merchant->id)->balance);
    }

    /**
     * @return array{0: Merchant, 1: Product, 2: string, 3: Supplier}
     */
    private function scenario(): array
    {
        $supplier = $this->createSupplier(['广东']);
        $supplier->update(['driver' => 'zhongkong', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
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
     * 按传入的字段算签名（$extra 也参与），status 跟文档示例一样是数字。
     *
     * @param array<string, string> $extra
     * @return array<string, mixed>
     */
    private function callbackParams(string $attemptNo, int $status, string $mobile, array $extra = [], string $secret = 'zk_secret'): array
    {
        $params = ['phone' => $mobile, 'status' => $status, 'thirdOrderId' => $attemptNo] + $extra;
        $signed = array_filter(array_map('strval', $params), static fn ($v, $k) => $v !== '' || ! in_array($k, ['param1', 'param2'], true), ARRAY_FILTER_USE_BOTH);
        $params['sign'] = ZhongkongDriver::sign($signed, $secret);

        return $params;
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
     * @param array<string, string> $config
     */
    private function request(array $config = []): RechargeRequest
    {
        return new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', '4028b8815f7d4ae2015f86ee6aa70009', $config + self::CONFIG);
    }

    private function driver(): ZhongkongDriver
    {
        return ApplicationContext::getContainer()->get(ZhongkongDriver::class);
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
