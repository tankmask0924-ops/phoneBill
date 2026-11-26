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
use App\Supplier\Driver\YbDriver;
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
 * YB 驱动：GB2312 md5 签名、下单、查单、余额、回调（docs/YB接口文档-2026.md 最后一节）。
 *
 * @internal
 * @coversNothing
 */
class YbDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const CONFIG = [
        'api_url' => 'http://yb.test/api.aspx',
        'customer_id' => '10789',
        'key' => 'yb_key',
        'customer_ip' => '1.2.3.4',
        'notify_url' => 'https://pb.test/notify/supplier/yb',
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
        // 按名升序拼 k=v（空值也参与、不做 URL 编码），末尾 &key=，md5 小写
        $this->assertSame(
            md5('CustomerID=10789&Game=&Method=SaleOrder.OrderInfoYB&OrderNo=A1&ReturnUrl=http://x/y?a=1&key=k'),
            YbDriver::sign(['OrderNo' => 'A1', 'Method' => 'SaleOrder.OrderInfoYB', 'ReturnUrl' => 'http://x/y?a=1', 'CustomerID' => '10789', 'Game' => '', 'Sign' => 'x'], 'k')
        );
        // 中文按 GB2312 编码后再 md5
        $this->assertSame(md5("OrderNo=\xD6\xD0&key=k"), YbDriver::sign(['OrderNo' => '中'], 'k'));
    }

    public function testRechargeSendsSignedFormAndMapsResults()
    {
        $this->respond(['Status' => ['Code' => 'success', 'Msg' => '订单已提交，正在处理中'], 'Data' => [['iOrderStatus' => '3', 'OrderID' => 'x', 'SaleOrderNo' => 'S001', 'SaleDate' => '2026-11-26 10:00:00']]]);
        $result = $this->driver()->recharge($this->request(null, '98.80'));

        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertSame('S001', $result->supplierOrderNo);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_FORM', $sent['method']);
        $this->assertSame('http://yb.test/api.aspx', $sent['url']);
        $form = $sent['payload'];
        $expected = [
            'Method' => 'Product.ApiBuyYB', 'CustomerID' => '10789', 'ProductID' => '12', 'BuyNum' => '1', 'Account' => '13800138000',
            'ChargeWay' => '', 'Game' => '', 'Zone' => '', 'Server' => '', 'OrderNo' => 'A0001',
            'ReturnUrl' => 'https://pb.test/notify/supplier/yb', 'CustomerIp' => '1.2.3.4', 'CustomerSalePrice' => '98.80',
        ];
        $this->assertSame($expected + ['Sign' => YbDriver::sign($expected, 'yb_key')], $form);
        $this->assertSame(
            md5('Account=13800138000&BuyNum=1&ChargeWay=&CustomerID=10789&CustomerIp=1.2.3.4&CustomerSalePrice=98.80&Game=&Method=Product.ApiBuyYB&OrderNo=A0001&ProductID=12&ReturnUrl=https://pb.test/notify/supplier/yb&Server=&Zone=&key=yb_key'),
            $form['Sign']
        );
        $this->assertStringNotContainsString('yb_key', (string) $result->request);

        // 没有成本价传 0
        $this->driver()->recharge($this->request());
        $this->assertSame('0', $this->http->requests[1]['payload']['CustomerSalePrice']);

        // iOrderStatus 1 直接成功、-1 直接失败（都没有回调）
        $this->respond(['Status' => ['Code' => 'success', 'Msg' => '充值成功'], 'Data' => [['iOrderStatus' => 1, 'SaleOrderNo' => 'S002']]]);
        $this->assertSame(RechargeStatus::Success, $this->driver()->recharge($this->request())->status);
        $this->respond(['Status' => ['Code' => 'success', 'Msg' => '充值失败'], 'Data' => [['iOrderStatus' => '-1', 'SaleOrderNo' => 'S003']]]);
        $result = $this->driver()->recharge($this->request());
        $this->assertSame(RechargeStatus::Failed, $result->status);
        $this->assertSame('S003', $result->supplierOrderNo);

        // Code=fail：失败换下一家
        $this->respond(['Status' => ['Code' => 'fail', 'Msg' => '请求非法，签名错误']]);
        $result = $this->driver()->recharge($this->request());
        $this->assertSame(RechargeStatus::Failed, $result->status);
        $this->assertFalse($result->callFailed);
        $this->assertStringContainsString('签名错误', $result->message);

        // 订单重复提交：上游已有这笔单，按处理中
        $this->respond(['Status' => ['Code' => 'fail', 'Msg' => '订单重复提交']]);
        $result = $this->driver()->recharge($this->request());
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertFalse($result->callFailed);
        $this->assertStringContainsString('订单重复提交', $result->message);

        // 没有响应体、返回无法解析、没有 Code：没有拿到返回值，转异常人工处理
        foreach (['', '<html>Bad Gateway</html>', '{"Data":[]}'] as $body) {
            $this->http->responses = [['status' => 502, 'body' => $body]];
            $result = $this->driver()->recharge($this->request());
            $this->assertTrue($result->callFailed, $body);
            $this->assertStringContainsString('没有拿到上游返回', $result->message);
        }

        // 上游按 GBK 返回中文也能解析
        $this->http->responses = [['status' => 200, 'body' => (string) mb_convert_encoding('{"Status":{"Code":"fail","Msg":"余额不足"}}', 'CP936', 'UTF-8')]];
        $result = $this->driver()->recharge($this->request());
        $this->assertSame(RechargeStatus::Failed, $result->status);
        $this->assertStringContainsString('余额不足', $result->message);
    }

    public function testQueryMapsOrderStatus()
    {
        foreach (['1' => RechargeStatus::Success, '-1' => RechargeStatus::Failed, '0' => RechargeStatus::Processing, '3' => RechargeStatus::Processing] as $status => $mapped) {
            $this->respond(['Status' => ['Code' => 'success', 'Msg' => '查询成功'], 'Data' => [['Status' => (string) $status, 'OrderNo' => 'A0001', 'ProcessNote' => '号码已停机', 'Temp1' => 'S001']]]);
            $result = $this->driver()->query($this->request());
            $this->assertSame($mapped, $result->status, (string) $status);
            $this->assertSame('S001', $result->supplierOrderNo);
        }
        $sent = $this->http->requests[0];
        $params = ['Method' => 'SaleOrder.OrderInfoYB', 'CustomerID' => '10789', 'OrderNo' => 'A0001'];
        $this->assertSame($params + ['Sign' => md5('CustomerID=10789&Method=SaleOrder.OrderInfoYB&OrderNo=A0001&key=yb_key')], $sent['payload']);

        // 失败原因取 ProcessNote
        $this->respond(['Status' => ['Code' => 'success', 'Msg' => '查询成功'], 'Data' => [['Status' => '-1', 'OrderNo' => 'A0001', 'ProcessNote' => '号码已停机']]]);
        $this->assertSame('号码已停机', $this->driver()->query($this->request())->message);

        // OrderNo 是平台单号也认
        $this->respond(['Status' => ['Code' => 'success', 'Msg' => '查询成功'], 'Data' => [['Status' => '1', 'OrderNo' => 'S001']]]);
        $this->assertSame(RechargeStatus::Success, $this->driver()->query($this->request('S001'))->status);

        // 无该订单信息：只记录，处理中
        $this->respond(['Status' => ['Code' => 'fail', 'Msg' => '无该订单信息']]);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertStringContainsString('无该订单', $result->message);

        // 其他报错、单号对不上、没有状态、无法解析：处理中
        foreach ([
            ['Status' => ['Code' => 'fail', 'Msg' => '请求非法，签名错误']],
            ['Status' => ['Code' => 'success', 'Msg' => '查询成功'], 'Data' => [['Status' => '1', 'OrderNo' => 'OTHER']]],
            ['Status' => ['Code' => 'success', 'Msg' => '查询成功'], 'Data' => []],
        ] as $body) {
            $this->respond($body);
            $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status, json_encode($body));
        }
        $this->http->responses = [['status' => 502, 'body' => 'Bad Gateway']];
        $this->assertSame(RechargeStatus::Processing, $this->driver()->query($this->request())->status);
    }

    public function testBalance()
    {
        $this->respond(['Status' => ['Code' => 'success', 'Msg' => '查询成功'], 'Data' => [['PID' => '10789', 'Balance' => '1653.25', 'CompanyName' => '某某网络', 'OverdraftMoney' => '0.00']]]);
        $this->assertSame('1653.25', $this->driver()->balance(self::CONFIG));
        $this->assertSame(
            ['Method' => 'Customer.CustomerInfo', 'CustomerID' => '10789', 'Sign' => md5('CustomerID=10789&Method=Customer.CustomerInfo&key=yb_key')],
            $this->http->requests[0]['payload']
        );

        $this->respond(['Status' => ['Code' => 'fail', 'Msg' => '客户不存在']]);
        $this->expectException(RuntimeException::class);
        $this->driver()->balance(self::CONFIG);
    }

    public function testOrderFlowSubmitsAndAppliesCallbacks()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['Status' => ['Code' => 'success', 'Msg' => '订单已提交，正在处理中'], 'Data' => [['iOrderStatus' => '3', 'SaleOrderNo' => 'S100']]]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame('S100', $attempt->supplier_order_no);
        $this->assertSame($attempt->attempt_no, $this->http->requests[0]['payload']['OrderNo']);
        $this->assertSame($mobile, $this->http->requests[0]['payload']['Account']);

        // 签名不对：回 fail，订单不变
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, '1', 'wrong')));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 状态未确认：回 SUCCESS，订单不变
        $this->assertSame([200, 'SUCCESS'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, '0')));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 回调成功（JSON，签名大写也认）
        $params = $this->callbackParams($attempt->attempt_no, '1');
        $params['Sign'] = strtoupper($params['Sign']);
        $this->assertSame([200, 'SUCCESS'], $this->supplierCallback($supplier, $params));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
    }

    public function testCallbackFailureAcceptsFormBody()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['Status' => ['Code' => 'success', 'Msg' => '订单已提交，正在处理中'], 'Data' => [['iOrderStatus' => '3', 'SaleOrderNo' => 'S200']]]);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        $this->assertSame([200, 'SUCCESS'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, '-1'), false));
        $attempt = OrderAttempt::find($attempt->id);
        $this->assertSame(OrderAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('号码已停机', $attempt->message);
        // 推任务换下一家：只有这一家，订单失败退款
        $this->process()->submit($order->id);
        $this->assertSame(Order::STATUS_FAILED, Order::find($order->id)->status);
    }

    /**
     * @return array<string, string>
     */
    private function callbackParams(string $attemptNo, string $status, ?string $key = null): array
    {
        $params = [
            'OrderNo' => $attemptNo,
            'OrderStatus' => $status,
            'OrderMessage' => $status === '-1' ? '号码已停机' : '充值成功',
            'Voucher' => '1101215465644574222',
            'Temp1' => 'S100',
            'Temp2' => '',
            'Temp3' => '',
        ];
        $params['Sign'] = md5("OrderNo={$attemptNo}&OrderStatus={$status}&key=" . ($key ?? 'yb_key'));

        return $params;
    }

    /**
     * 文档写回调是 JSON，表单也测。
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

    /**
     * @return array{0: Merchant, 1: Product, 2: string, 3: Supplier}
     */
    private function scenario(): array
    {
        $supplier = $this->createSupplier(['广东']);
        $supplier->update(['driver' => 'yb', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
        $supplierProduct = $this->createSupplierProduct($supplier, ['cmcc'], 100, '98.80');
        $supplierProduct->update(['external_code' => '12']);
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

    private function request(?string $supplierOrderNo = null, ?string $costPrice = null): RechargeRequest
    {
        return new RechargeRequest('A0001', '13800138000', 100, 'cmcc', '广东', '12', self::CONFIG, $supplierOrderNo, $costPrice);
    }

    private function driver(): YbDriver
    {
        return ApplicationContext::getContainer()->get(YbDriver::class);
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
