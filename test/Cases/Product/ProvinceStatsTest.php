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

namespace HyperfTest\Cases\Product;

use App\Model\Merchant;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\Product;
use App\Model\ProvinceDailyStat;
use App\Model\SupplierProduct;
use App\Network\HttpClient;
use App\Service\Order\OrderDispatcher;
use App\Service\Product\ProvinceStatsService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\Cases\Admin\CreatesAdmins;
use HyperfTest\Cases\Admin\CreatesCatalog;
use HyperfTest\HttpTestCase;
use HyperfTest\Support\FakeHttpClient;
use HyperfTest\Support\FakeOrderDispatcher;

use function Hyperf\Support\make;

/**
 * @internal
 * @coversNothing
 */
class ProvinceStatsTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

    /** 统计按整天覆盖写，用一个不会有真实订单的日期，免得和开发库里的数据互相影响 */
    private const DAY = '2001-02-05';

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        ApplicationContext::getContainer()->set(OrderDispatcher::class, new FakeOrderDispatcher());
        ApplicationContext::getContainer()->set(HttpClient::class, new FakeHttpClient());
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        ProvinceDailyStat::where('stat_date', self::DAY)->delete();
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testGroupsByProvinceAndFinalSupplier()
    {
        $a = $this->createSupplier();
        $b = $this->createSupplier();
        $spA = $this->createSupplierProduct($a, ['cmcc', 'cucc']);
        $spB = $this->createSupplierProduct($b, ['cmcc', 'cucc']);
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct([$spA->id => 1, $spB->id => 2]);

        // 广东移动：A 成功一单、A 还在充值一单；A、B 都失败的算 B；没提交过供应商的记 0
        $this->order($merchant, $product, '广东', 'cmcc', Order::STATUS_SUCCESS, [[$spA, OrderAttempt::STATUS_SUCCESS]]);
        $this->order($merchant, $product, '广东', 'cmcc', Order::STATUS_PROCESSING, [[$spA, OrderAttempt::STATUS_PROCESSING]]);
        $this->order($merchant, $product, '广东', 'cmcc', Order::STATUS_FAILED, [[$spA, OrderAttempt::STATUS_FAILED], [$spB, OrderAttempt::STATUS_FAILED]]);
        $this->order($merchant, $product, '广东', 'cmcc', Order::STATUS_FAILED, []);
        // 广西联通：A 失败换 B 成功，只算 B
        $this->order($merchant, $product, '广西', 'cucc', Order::STATUS_SUCCESS, [[$spA, OrderAttempt::STATUS_FAILED], [$spB, OrderAttempt::STATUS_SUCCESS]]);
        // 前一天下的单不算
        $this->order($merchant, $product, '广西', 'cucc', Order::STATUS_SUCCESS, [[$spA, OrderAttempt::STATUS_SUCCESS]], '2001-02-04 23:59:59');

        $this->assertSame(4, $this->stats()->compute(self::DAY));
        $this->assertSame(4, $this->stats()->compute(self::DAY), '重算覆盖，不会多出行');

        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        $result = $this->body($this->jsonRequest('GET', '/admin/product-stats/provinces?date=' . self::DAY, $token));
        $this->assertSame(self::DAY, $result['stat_date']);
        $this->assertNotNull($result['computed_at']);
        $this->assertSame([
            'order_count' => 5, 'success_count' => 2, 'failed_count' => 2, 'unfinished_count' => 1,
            'success_amount' => '199.00', 'profit' => '3.00', 'success_rate' => '50.00',
        ], $result['summary']);

        [$guangdong, $guangxi] = $result['provinces'];
        $this->assertSame('广东', $guangdong['province'], '按订单数从多到少');
        $this->assertSame(4, $guangdong['order_count']);
        $this->assertSame('33.33', $guangdong['success_rate']);
        $this->assertSame('99.50', $guangdong['success_amount']);
        $this->assertSame('1.50', $guangdong['profit']);
        $suppliers = array_column($guangdong['suppliers'], null, 'supplier_id');
        $this->assertSame($a->id, $guangdong['suppliers'][0]['supplier_id'], '按订单数从多到少');
        $this->assertEqualsCanonicalizing([$a->id, $b->id, 0], array_keys($suppliers));
        $this->assertSame($a->name, $suppliers[$a->id]['supplier_name']);
        $this->assertSame(['order_count' => 2, 'success_count' => 1, 'failed_count' => 0, 'unfinished_count' => 1], array_intersect_key($suppliers[$a->id], array_flip(['order_count', 'success_count', 'failed_count', 'unfinished_count'])));
        $this->assertSame('100.00', $suppliers[$a->id]['success_rate'], '还在充值的不进分母');
        $this->assertSame('0.00', $suppliers[$b->id]['success_rate']);
        $this->assertNull($suppliers[0]['supplier_name']);

        $this->assertSame('广西', $guangxi['province']);
        $this->assertSame([$b->id], array_column($guangxi['suppliers'], 'supplier_id'), '换过供应商的只算最终那家');
        $this->assertSame('100.00', $guangxi['suppliers'][0]['success_rate']);

        $cucc = $this->body($this->jsonRequest('GET', '/admin/product-stats/provinces?date=' . self::DAY . '&operator=cucc', $token));
        $this->assertSame(['广西'], array_column($cucc['provinces'], 'province'));

        $this->assertSame(403, $this->jsonRequest('GET', '/admin/product-stats/provinces', $this->loginAs($this->createAdminWithPermissions(['product.view'])))->getStatusCode());
    }

    public function testDayWithNoStats()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        $response = $this->jsonRequest('GET', '/admin/product-stats/provinces?date=2001-02-06', $token);
        $this->assertSame(200, $response->getStatusCode());
        $result = $this->body($response);
        $this->assertSame([], $result['provinces']);
        $this->assertNull($result['computed_at']);
        $this->assertSame(0, $result['summary']['order_count']);
        $this->assertNull($result['summary']['success_rate']);
    }

    /**
     * @param list<array{0: SupplierProduct, 1: string}> $attempts 按提交顺序
     */
    private function order(Merchant $merchant, Product $product, string $province, string $operator, string $status, array $attempts, string $createdAt = self::DAY . ' 10:00:00'): Order
    {
        $order = Order::create([
            'order_no' => date('YmdHis') . random_int(10000000, 99999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => $this->uniq('M'),
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'mobile' => '1009' . random_int(1000000, 9999999),
            'operator' => $operator,
            'province' => $province,
            'face_value' => 100,
            'sale_price' => '99.50',
            'status' => $status,
            'notify_status' => Order::NOTIFY_NONE,
        ]);
        foreach ($attempts as $i => [$supplierProduct, $attemptStatus]) {
            OrderAttempt::create([
                'order_id' => $order->id,
                'attempt_no' => $order->order_no . sprintf('%02d', $i + 1),
                'supplier_id' => $supplierProduct->supplier_id,
                'supplier_product_id' => $supplierProduct->id,
                'cost_price' => '98.00',
                'status' => $attemptStatus,
                'submitted_at' => $createdAt,
            ]);
            if ($attemptStatus === OrderAttempt::STATUS_SUCCESS) {
                $order->update(['supplier_id' => $supplierProduct->supplier_id, 'supplier_product_id' => $supplierProduct->id, 'cost_price' => '98.00', 'profit' => '1.50']);
            }
        }
        Order::where('id', $order->id)->update(['created_at' => $createdAt]);

        return $order;
    }

    private function stats(): ProvinceStatsService
    {
        return ApplicationContext::getContainer()->get(ProvinceStatsService::class);
    }
}
