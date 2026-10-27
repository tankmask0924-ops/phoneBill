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

namespace HyperfTest\Cases\Merchant;

use App\Model\Merchant;
use App\Model\MerchantUser;
use App\Model\Order;
use App\Model\Product;
use App\Model\SystemSetting;
use App\Service\Order\OrderDispatcher;
use App\Service\Setting\OrderSettingService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\Cases\Admin\CreatesAdmins;
use HyperfTest\Cases\Admin\CreatesCatalog;
use HyperfTest\HttpTestCase;
use HyperfTest\Support\FakeOrderDispatcher;

use function Hyperf\Support\make;

/**
 * 商户后台批量下单：预览逐个检查、提交逐个下单扣款、同一批重复提交不重复扣款。
 *
 * @internal
 * @coversNothing
 */
class MerchantBatchOrderTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

    private FakeOrderDispatcher $dispatcher;

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->dispatcher = new FakeOrderDispatcher();
        ApplicationContext::getContainer()->set(OrderDispatcher::class, $this->dispatcher);
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testPreviewChecksEveryMobile()
    {
        [$merchant, $product, $mobile] = $this->scenario('300.00');
        $token = $this->login($merchant);
        // 号段是电信、商品只开了移动价：按商品的运营商可以下单（docs/project.md 2.3）
        $ported = $this->createSegment('ctcc', '广东');
        $virtual = $this->createSegment('cmcc', '广东', true);

        $result = $this->body($this->jsonRequest('POST', '/merchant/batch-orders/preview', $token, [
            'product_code' => $product->code,
            'mobiles' => [" {$mobile} ", '', $mobile, '123', $ported, $virtual],
        ]));

        $this->assertSame([$mobile, $mobile, '123', $ported, $virtual], array_column($result['items'], 'mobile'), '去掉空白和空行，保留顺序');
        $this->assertSame([true, false, false, true, false], array_column($result['items'], 'ok'));
        $this->assertSame(['mobile' => $mobile, 'ok' => true, 'operator' => 'cmcc', 'segment_operator' => 'cmcc', 'province' => '广东', 'price' => '99.50', 'error' => null], $result['items'][0]);
        $this->assertStringContainsString('号码重复', $result['items'][1]['error']);
        $this->assertSame('手机号格式不对，要 11 位手机号', $result['items'][2]['error']);
        $this->assertSame(['mobile' => $ported, 'ok' => true, 'operator' => 'cmcc', 'segment_operator' => 'ctcc', 'province' => '广东', 'price' => '99.50', 'error' => null], $result['items'][3]);
        $this->assertStringContainsString('虚拟运营商', $result['items'][4]['error']);
        $this->assertSame(2, $result['valid_count']);
        $this->assertSame('199.00', $result['total_amount']);
        $this->assertSame('300.00', $result['balance']);

        // 预览不建单、不扣款
        $this->assertSame(0, Order::where('merchant_id', $merchant->id)->count());
        $this->assertSame('300.00', Merchant::find($merchant->id)->balance);
    }

    public function testPreviewFlagsMobilesWithOrdersInProgress()
    {
        [$merchant, $product, $first] = $this->scenario('300.00');
        $second = substr($first, 0, 7) . '5678';
        $token = $this->login($merchant);
        $this->jsonRequest('POST', '/merchant/batch-orders', $token, ['product_code' => $product->code, 'mobiles' => [$first], 'batch_no' => 'B' . bin2hex(random_bytes(8))]);

        // 刚下过同面值的单、还在处理中：预览就标出来，不算进合计
        $result = $this->body($this->jsonRequest('POST', '/merchant/batch-orders/preview', $token, ['product_code' => $product->code, 'mobiles' => [$first, $second]]));
        $this->assertSame([false, true], array_column($result['items'], 'ok'));
        $this->assertStringContainsString('分钟内有正在处理的同面值订单', $result['items'][0]['error']);
        $this->assertSame(1, $result['valid_count']);
        $this->assertSame('99.50', $result['total_amount']);

        // 那一单出了结果就不再拦
        Order::where('merchant_id', $merchant->id)->update(['status' => Order::STATUS_FAILED]);
        $result = $this->body($this->jsonRequest('POST', '/merchant/batch-orders/preview', $token, ['product_code' => $product->code, 'mobiles' => [$first, $second]]));
        $this->assertSame([true, true], array_column($result['items'], 'ok'));
        $this->assertSame('199.00', $result['total_amount']);
    }

    public function testSubmitPlacesOrdersOneByOneAndResubmitIsIdempotent()
    {
        [$merchant, $product, $first] = $this->scenario('150.00');
        $second = substr($first, 0, 7) . '5678';
        $token = $this->login($merchant);
        $batch = ['product_code' => $product->code, 'mobiles' => [$first, $second], 'batch_no' => 'B' . bin2hex(random_bytes(8))];

        // 余额只够一单：第一个成功，第二个余额不足，互不影响
        $result = $this->body($this->jsonRequest('POST', '/merchant/batch-orders', $token, $batch));
        $this->assertSame([1, 1], [$result['success_count'], $result['failed_count']]);
        [$ok, $failed] = $result['items'];
        $this->assertTrue($ok['ok']);
        $this->assertSame($batch['batch_no'] . '-001', $ok['merchant_order_no']);
        $this->assertSame('processing', $ok['status']);
        $this->assertSame('99.50', $ok['amount']);
        $this->assertFalse($failed['ok']);
        $this->assertSame($batch['batch_no'] . '-002', $failed['merchant_order_no']);
        $this->assertStringContainsString('余额不足', $failed['error']);
        $this->assertSame('50.50', $result['balance']);
        $order = Order::where('order_no', $ok['order_no'])->first();
        $this->assertSame([$merchant->id, $first, $batch['batch_no'] . '-001'], [$order->merchant_id, $order->mobile, $order->merchant_order_no]);
        $this->assertSame([$order->id], array_column($this->dispatcher->submits, 'order_id'), '建单后照常推提交任务');

        // 同一批再提交一次（比如超时后重点）：返回之前的订单，不重复扣款
        $again = $this->body($this->jsonRequest('POST', '/merchant/batch-orders', $token, $batch));
        $this->assertSame($ok['order_no'], $again['items'][0]['order_no']);
        $this->assertSame(1, Order::where('merchant_id', $merchant->id)->count());
        $this->assertSame('50.50', Merchant::find($merchant->id)->balance);

        // 批次号被拿去提交别的号码：不能把旧单当成结果
        $reused = $this->body($this->jsonRequest('POST', '/merchant/batch-orders', $token, ['mobiles' => [$second]] + $batch));
        $this->assertFalse($reused['items'][0]['ok']);
        $this->assertStringContainsString('批次号已用过', $reused['items'][0]['error']);
    }

    public function testRejectsInvalidRequests()
    {
        [$merchant, $product, $mobile] = $this->scenario('300.00');
        $token = $this->login($merchant);
        $batch = ['product_code' => $product->code, 'mobiles' => [$mobile], 'batch_no' => 'B' . bin2hex(random_bytes(8))];

        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/batch-orders', $token, ['batch_no' => 'x-1'] + $batch)->getStatusCode(), '批次号格式不对');
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/batch-orders', $token, ['mobiles' => ['', ' ']] + $batch)->getStatusCode(), '没有号码');
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/batch-orders/preview', $token, ['product_code' => ''] + $batch)->getStatusCode(), '没选商品');
        $tooMany = array_map(static fn (int $i) => '1380000' . sprintf('%04d', $i), range(1, 201));
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/batch-orders/preview', $token, ['mobiles' => $tooMany] + $batch)->getStatusCode(), '超过 200 个');
        $this->assertSame(401, $this->jsonRequest('POST', '/merchant/batch-orders', null, $batch)->getStatusCode());

        // 商户停用后还能登录看数据，但不能下单
        $merchant->update(['status' => 'disabled']);
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/batch-orders/preview', $token, $batch)->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/batch-orders', $token, $batch)->getStatusCode());
        $this->assertSame(0, Order::where('merchant_id', $merchant->id)->count());
    }

    /**
     * @return array{0: Merchant, 1: Product, 2: string}
     */
    public function testDuplicateWindowComesFromSystemSetting()
    {
        $original = SystemSetting::where('key', OrderSettingService::KEY)->first()?->value;
        SystemSetting::where('key', OrderSettingService::KEY)->delete();
        make(OrderSettingService::class)->flush();
        try {
            [$merchant, $product, $mobile] = $this->scenario('500.00');
            $token = $this->login($merchant);
            $admin = $this->loginAs($this->createAdminWithPermissions(['setting.manage']));
            $submit = fn () => $this->body($this->jsonRequest('POST', '/merchant/batch-orders', $token, [
                'product_code' => $product->code, 'mobiles' => [$mobile], 'batch_no' => 'B' . bin2hex(random_bytes(8)),
            ]))['items'][0];
            $preview = fn () => $this->body($this->jsonRequest('POST', '/merchant/batch-orders/preview', $token, ['product_code' => $product->code, 'mobiles' => [$mobile]]))['items'][0];

            // 后台没设置过：用环境变量的默认值
            $default = (int) make(ConfigInterface::class)->get('order.duplicate_window_minutes');
            $timeout = (int) make(ConfigInterface::class)->get('order.abnormal_after_minutes');
            $this->assertSame(['duplicate_window_minutes' => $default, 'timeout_minutes' => $timeout], $this->body($this->jsonRequest('GET', '/admin/settings/order', $admin)));
            foreach ([-1, 1441, 'x', 1.5, null] as $bad) {
                $this->assertSame(422, $this->jsonRequest('PUT', '/admin/settings/order', $admin, ['duplicate_window_minutes' => $bad])->getStatusCode(), var_export($bad, true));
            }

            // 设成 0：不拦截，同号码同面值连着下两单都成功
            $this->assertSame(['duplicate_window_minutes' => 0, 'timeout_minutes' => $timeout], $this->body($this->jsonRequest('PUT', '/admin/settings/order', $admin, ['duplicate_window_minutes' => 0])), '只改传了的项');
            $this->assertTrue($submit()['ok']);
            $this->assertTrue($preview()['ok'], '预览也不再标出来');
            $this->assertTrue($submit()['ok']);
            $this->assertSame(2, Order::where('merchant_id', $merchant->id)->count());

            // 改回拦截：立即生效，提示里带新的分钟数
            $this->jsonRequest('PUT', '/admin/settings/order', $admin, ['duplicate_window_minutes' => 5]);
            $this->assertStringContainsString('5 分钟内有正在处理的同面值订单', $preview()['error']);
            $this->assertFalse($submit()['ok']);
            $this->assertSame(2, Order::where('merchant_id', $merchant->id)->count());

            $noPermission = $this->loginAs($this->createAdminWithPermissions(['order.view']));
            $this->assertSame(403, $this->jsonRequest('PUT', '/admin/settings/order', $noPermission, ['duplicate_window_minutes' => 0])->getStatusCode());
        } finally {
            SystemSetting::where('key', OrderSettingService::KEY)->delete();
            if ($original !== null) {
                SystemSetting::create(['key' => OrderSettingService::KEY, 'value' => $original]);
            }
            make(OrderSettingService::class)->flush();
        }
    }

    private function scenario(string $balance): array
    {
        $supplier = $this->createSupplier(['广东']);
        $supplierProduct = $this->createSupplierProduct($supplier, ['cmcc', 'ctcc'], 100, '98.80');
        $product = $this->createProduct([$supplierProduct->id => 1]);
        $merchant = $this->createMerchant($balance);
        $this->openProduct($merchant, $product, ['cmcc' => '99.50']);

        return [$merchant, $product, $this->createSegment('cmcc', '广东')];
    }

    private function login(Merchant $merchant): string
    {
        $user = MerchantUser::create([
            'merchant_id' => $merchant->id,
            'username' => $this->uniq('mu_'),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => 'active',
        ]);
        $response = $this->client->request('POST', '/merchant/auth/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['username' => $user->username, 'password' => self::PASSWORD],
        ]);

        return $this->body($response)['token'];
    }
}
