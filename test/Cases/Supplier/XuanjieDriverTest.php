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
use App\Supplier\Driver\XuanjieDriver;
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
 * 炫捷驱动：表单 + 固定顺序 md5 签名、下单、查单、余额、回调（docs/炫捷.md 最后一节）。
 *
 * @internal
 * @coversNothing
 */
class XuanjieDriverTest extends HttpTestCase
{
    use CreatesCatalog;

    private const CONFIG = [
        'api_url' => 'http://xj.test:8080/',
        'agent_id' => '200000',
        'key' => 'xj_key',
        'notify_url' => 'https://pb.test/notify/supplier/xj',
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

    public function testSignFollowsDocumentOrder()
    {
        $this->assertSame(md5('szAgentId=200000&szKey=k'), XuanjieDriver::sign(['szAgentId' => '200000'], 'k'));
        $this->assertSame(
            md5('szAgentId=200000&szOrderId=A1&szKey=k'),
            XuanjieDriver::sign(['szAgentId' => '200000', 'szOrderId' => 'A1'], 'k')
        );
        // 回调：固定顺序，szRtnMsg、szVerifyString 和文档外字段不参与
        $this->assertSame(
            md5('szAgentId=200000&szOrderId=A1&szPhoneNum=13800138000&nDemo=100&fSalePrice=98.50&nFlag=2&szKey=k'),
            XuanjieDriver::callbackSign([
                'nFlag' => '2', 'szRtnMsg' => '{"no":"1"}', 'szVerifyString' => 'x', 'other' => 'y', 'fSalePrice' => '98.50',
                'nDemo' => '100', 'szPhoneNum' => '13800138000', 'szOrderId' => 'A1', 'szAgentId' => '200000',
            ], 'k')
        );
    }

    public function testRechargeSendsSignedFormAndAcceptsZero()
    {
        $this->respond(['nRtn' => 0, 'szOrderId' => 'A0001', 'fNBalance' => 10.88, 'fSalePrice' => 98.5, 'szRtnCode' => '下单成功']);
        $before = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        $result = $this->driver()->recharge($this->request('ctcc'));

        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertFalse($result->callFailed);
        $this->assertNull($result->supplierOrderNo);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_FORM', $sent['method']);
        $this->assertSame('http://xj.test:8080/plat/api/old/submitorder', $sent['url']);
        $form = $sent['payload'];
        $this->assertSame([
            'szAgentId', 'szOrderId', 'szPhoneNum', 'nMoney', 'nSortType', 'nProductClass', 'nProductType', 'szTimeStamp',
            'szNotifyUrl', 'szFormat', 'szVerifyString',
        ], array_keys($form));
        $this->assertSame('200000', $form['szAgentId']);
        $this->assertSame('A0001', $form['szOrderId']);
        $this->assertSame('13800138000', $form['szPhoneNum']);
        $this->assertSame('100', $form['nMoney']);
        $this->assertSame('3', $form['nSortType']);
        $this->assertSame('1', $form['nProductClass']);
        $this->assertSame('1', $form['nProductType']);
        $this->assertSame(self::CONFIG['notify_url'], $form['szNotifyUrl']);
        $this->assertSame('json', $form['szFormat']);
        $this->assertArrayNotHasKey('szProductId', $form);
        // 北京时间 yyyy-MM-dd HH:mm:ss
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $form['szTimeStamp']);
        $sentAt = new DateTimeImmutable($form['szTimeStamp'], new DateTimeZone('Asia/Shanghai'));
        $this->assertLessThanOrEqual(2, abs($sentAt->getTimestamp() - $before->getTimestamp()));
        $this->assertSame(md5(
            'szAgentId=200000&szOrderId=A0001&szPhoneNum=13800138000&nMoney=100&nSortType=3&nProductClass=1&nProductType=1&szTimeStamp='
            . $form['szTimeStamp'] . '&szKey=xj_key'
        ), $form['szVerifyString']);
        $this->assertNotContains('xj_key', $form);

        // 运营商编码：移动 1、联通 2
        $this->driver()->recharge($this->request('cmcc'));
        $this->assertSame('1', $this->http->requests[1]['payload']['nSortType']);
        $this->driver()->recharge($this->request('cucc'));
        $this->assertSame('2', $this->http->requests[2]['payload']['nSortType']);

        // 不支持的运营商：不提交，直接失败换下一家
        $result = $this->driver()->recharge($this->request('cbn'));
        $this->assertSame(RechargeStatus::Failed, $result->status);
        $this->assertCount(3, $this->http->requests);
    }

    public function testRechargeMapsDocumentCodes()
    {
        // 只有 0 算受理：文档 2.2 的失败码、3003 订单号重复、表外的码，一律失败换下一家
        foreach (['1000', '1001', '1003', '1004', '2001', '2002', '2003', '2020', '2021', '1006', '2030', '3003', '1', '999', '5011'] as $code) {
            $this->respond(['nRtn' => (int) $code, 'szOrderId' => 'A0001', 'szRtnCode' => '']);
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Failed, $result->status, "nRtn {$code}");
            $this->assertFalse($result->callFailed, "nRtn {$code}");
            $this->assertStringContainsString("[{$code}]", $result->message);
        }
        $this->respond(['nRtn' => 3003, 'szRtnCode' => '']);
        $this->assertStringContainsString('[3003] 订单号重复', $this->driver()->recharge($this->request())->message);
        $this->respond(['nRtn' => '999', 'szRtnCode' => '系统异常']);
        $this->assertStringContainsString('[999] 系统异常', $this->driver()->recharge($this->request())->message);

        // 没有响应体、返回无法解析、HTTP 出错：没有拿到返回值，转异常人工处理
        foreach ([['status' => 200, 'body' => ''], ['status' => 200, 'body' => '<return><nRtn>0</nRtn></return>'], ['status' => 502, 'body' => '<html>Bad Gateway</html>']] as $response) {
            $this->http->responses = [$response];
            $result = $this->driver()->recharge($this->request());
            $this->assertSame(RechargeStatus::Processing, $result->status);
            $this->assertTrue($result->callFailed);
            $this->assertStringContainsString('没有拿到上游返回', $result->message);
        }
    }

    public function testQueryMapsDocumentCodes()
    {
        $this->respond(['nRtn' => 5012, 'szOrderId' => 'A0001', 'fSalePrice' => 98.5, 'szRtnCode' => '查询成功', 'szRtnMsg' => '']);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Success, $result->status);
        $sent = $this->http->requests[0];
        $this->assertSame('POST_FORM', $sent['method']);
        $this->assertSame('http://xj.test:8080/plat/api/old/queryorder', $sent['url']);
        $this->assertSame([
            'szAgentId' => '200000',
            'szOrderId' => 'A0001',
            'szFormat' => 'json',
            'szVerifyString' => md5('szAgentId=200000&szOrderId=A0001&szKey=xj_key'),
        ], $sent['payload']);

        // 订单号大小写不敏感
        $this->respond(['nRtn' => 5012, 'szOrderId' => 'a0001']);
        $this->assertSame(RechargeStatus::Success, $this->driver()->query($this->request())->status);

        $this->respond(['nRtn' => 5013, 'szOrderId' => 'A0001', 'szRtnCode' => '号码已停机']);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Failed, $result->status);
        $this->assertSame('号码已停机', $result->message);

        $this->respond(['nRtn' => 5011, 'szOrderId' => 'A0001', 'szRtnCode' => '充值中']);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertNull($result->message);

        // 5005 订单不存在：只记录，订单状态不动
        $this->respond(['nRtn' => 5005, 'szOrderId' => 'A0001', 'szRtnCode' => '订单不存在']);
        $result = $this->driver()->query($this->request());
        $this->assertSame(RechargeStatus::Processing, $result->status);
        $this->assertFalse($result->callFailed);
        $this->assertStringContainsString('只记录', $result->message);

        // 5002 查询频繁、5003 / 5004 不处理订单，表外的码、单号对不上、没拿到返回：处理中
        foreach ([
            ['nRtn' => 5002, 'szRtnCode' => '查询频繁'],
            ['nRtn' => 5003, 'szRtnCode' => '参数有误'],
            ['nRtn' => 5004, 'szRtnCode' => ''],
            ['nRtn' => 9999, 'szRtnCode' => '未知'],
            ['nRtn' => 5012, 'szOrderId' => 'OTHER'],
            ['szRtnCode' => '没有 nRtn'],
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

    public function testBalance()
    {
        $this->respond(['nRtn' => 0, 'szAgentId' => '200000', 'fBalance' => 1234.56, 'fCredit' => 0.0, 'szRtnCode' => '查询成功']);
        $this->assertSame('1234.56', $this->driver()->balance(self::CONFIG));
        $sent = $this->http->requests[0];
        $this->assertSame('POST_FORM', $sent['method']);
        $this->assertSame('http://xj.test:8080/plat/api/old/queryBalance', $sent['url']);
        $this->assertSame([
            'szAgentId' => '200000',
            'szFormat' => 'json',
            'szVerifyString' => md5('szAgentId=200000&szKey=xj_key'),
        ], $sent['payload']);

        // 5002：30 秒只能查一次
        $this->respond(['nRtn' => 5002, 'szAgentId' => '200000', 'fBalance' => 0.0, 'szRtnCode' => 'frequent_Queries']);
        try {
            $this->driver()->balance(self::CONFIG);
            $this->fail('查询频繁应抛异常');
        } catch (RuntimeException $e) {
            $this->assertSame('查询太频繁，30 秒后再刷新', $e->getMessage());
        }

        foreach ([['nRtn' => 1], ['nRtn' => 999], ['nRtn' => 0]] as $body) {
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

        $this->respond(['nRtn' => 0, 'szRtnCode' => '下单成功']);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);
        $this->assertSame($attempt->attempt_no, $this->http->requests[0]['payload']['szOrderId']);
        $this->assertSame(self::CONFIG['notify_url'], $this->http->requests[0]['payload']['szNotifyUrl']);

        // 签名不对、密钥不对、szAgentId 不对：拒绝，订单不变
        $forged = $this->callbackParams($attempt->attempt_no, 2, $mobile);
        $forged['szVerifyString'] = str_repeat('0', 32);
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $forged));
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 2, $mobile, [], 'wrong')));
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 2, $mobile, ['szAgentId' => '999999'])));
        // 改了参与签名的状态：验签不过
        $tampered = $this->callbackParams($attempt->attempt_no, 3, $mobile);
        $tampered['nFlag'] = '2';
        $this->assertSame([400, 'fail'], $this->supplierCallback($supplier, $tampered));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // 其他 nFlag：未确认，回 ok 但不处理订单
        $this->assertSame([200, 'ok'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 1, $mobile)));
        $this->assertSame(Order::STATUS_PROCESSING, Order::find($order->id)->status);

        // szRtnMsg 不参与签名，改了也照样通过
        $params = $this->callbackParams($attempt->attempt_no, 2, $mobile);
        $params['szRtnMsg'] = '{"no":"123","pass":"456","validitydate":"2021-01-01"}';
        $this->assertSame([200, 'ok'], $this->supplierCallback($supplier, $params));
        $this->assertSame(Order::STATUS_SUCCESS, Order::find($order->id)->status);
    }

    public function testCallbackFailureFailsOrder()
    {
        [$merchant, $product, $mobile, $supplier] = $this->scenario();

        $this->respond(['nRtn' => 0, 'szRtnCode' => '下单成功']);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $attempt = OrderAttempt::where('order_id', $order->id)->first();

        $this->assertSame([200, 'ok'], $this->supplierCallback($supplier, $this->callbackParams($attempt->attempt_no, 3, $mobile)));
        $this->assertSame(OrderAttempt::STATUS_FAILED, OrderAttempt::find($attempt->id)->status);

        // 回调后推的重新提交任务：这个省份只有这一家，订单直接失败退款
        $this->process()->submit($order->id);
        $this->assertSame(Order::STATUS_FAILED, Order::find($order->id)->status);
        $this->assertSame('200.00', Merchant::find($merchant->id)->balance);
    }

    public function testRejectedSubmitFailsOverAndNoReplyMarksOrderAbnormal()
    {
        [$merchant, $product, $mobile] = $this->scenario();

        // 表外的码：上游有返回，判失败；只有这一家，订单失败退款
        $this->respond(['nRtn' => 999, 'szRtnCode' => '系统异常']);
        $order = $this->placeOrder($merchant, $product, $mobile);
        $this->process()->submit($order->id);
        $this->assertSame(Order::STATUS_FAILED, Order::find($order->id)->status);
        $this->assertSame('200.00', Merchant::find($merchant->id)->balance);
        $this->assertStringContainsString('[999]', OrderAttempt::where('order_id', $order->id)->first()->message);

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
        $supplier->update(['driver' => 'xuanjie', 'config' => make(Encrypter::class)->encrypt(json_encode(self::CONFIG))]);
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
     * 照文档字段造一份回调，$extra 覆盖或追加字段；签名只算文档列出的字段。
     *
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function callbackParams(string $attemptNo, int $flag, string $mobile, array $extra = [], string $key = 'xj_key'): array
    {
        $params = $extra + [
            'szAgentId' => '200000',
            'szOrderId' => $attemptNo,
            'szPhoneNum' => $mobile,
            'nDemo' => '100',
            'fSalePrice' => '98.50',
            'nFlag' => (string) $flag,
            'szRtnMsg' => '',
        ];
        $params['szVerifyString'] = XuanjieDriver::callbackSign($params, $key);

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

    private function request(string $operator = 'cmcc'): RechargeRequest
    {
        return new RechargeRequest('A0001', '13800138000', 100, $operator, '广东', '68', self::CONFIG);
    }

    private function driver(): XuanjieDriver
    {
        return ApplicationContext::getContainer()->get(XuanjieDriver::class);
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
