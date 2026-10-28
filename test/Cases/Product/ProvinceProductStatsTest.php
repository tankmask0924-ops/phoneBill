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
use App\Model\Product;
use App\Model\ProvinceProductDailyStat;
use App\Network\HttpClient;
use App\Service\Order\OrderDispatcher;
use App\Service\Product\ProvinceProductStatsService;
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
class ProvinceProductStatsTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

    /** 统计按整天覆盖写，用一个不会有真实订单的日期，也和 ProvinceStatsTest 的日期错开 */
    private const DAY = '2001-03-05';

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
        ProvinceProductDailyStat::where('stat_date', self::DAY)->delete();
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testGroupsByProvinceAndProduct()
    {
        $merchant = $this->createMerchant('100.00');
        $p100 = $this->createProduct([], 100);
        $p50 = $this->createProduct([], 50);

        // 广东移动：100 元成功一单、失败一单、还在充值一单；50 元成功一单
        $this->order($merchant, $p100, '广东', 'cmcc', Order::STATUS_SUCCESS);
        $this->order($merchant, $p100, '广东', 'cmcc', Order::STATUS_FAILED);
        $this->order($merchant, $p100, '广东', 'cmcc', Order::STATUS_PROCESSING);
        $this->order($merchant, $p50, '广东', 'cmcc', Order::STATUS_SUCCESS);
        // 广西联通：50 元成功一单
        $this->order($merchant, $p50, '广西', 'cucc', Order::STATUS_SUCCESS);
        // 前一天下的单不算
        $this->order($merchant, $p50, '广西', 'cucc', Order::STATUS_SUCCESS, '2001-03-04 23:59:59');

        $this->assertSame(3, $this->stats()->compute(self::DAY));
        $this->assertSame(3, $this->stats()->compute(self::DAY), '重算覆盖，不会多出行');

        // 删掉的商品，历史统计照常显示名字
        $p50->delete();

        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        $result = $this->body($this->jsonRequest('GET', '/admin/product-stats/province-products?date=' . self::DAY, $token));
        $this->assertSame(self::DAY, $result['stat_date']);
        $this->assertNotNull($result['computed_at']);
        $this->assertSame([
            'order_count' => 5, 'success_count' => 3, 'failed_count' => 1, 'unfinished_count' => 1,
            'success_amount' => '298.50', 'profit' => '4.50', 'success_rate' => '75.00',
        ], $result['summary']);

        [$guangdong, $guangxi] = $result['provinces'];
        $this->assertSame('广东', $guangdong['province'], '按订单数从多到少');
        $this->assertSame(4, $guangdong['order_count']);
        $this->assertSame('66.66', $guangdong['success_rate'], '成功率截断不四舍五入，和其他统计一致');
        $this->assertSame([$p100->id, $p50->id], array_column($guangdong['products'], 'product_id'), '按订单数从多到少');
        $item = $guangdong['products'][0];
        $this->assertSame($p100->code, $item['product_code']);
        $this->assertSame($p100->name, $item['product_name']);
        $this->assertSame(100, $item['face_value']);
        $this->assertSame(['order_count' => 3, 'success_count' => 1, 'failed_count' => 1, 'unfinished_count' => 1], array_intersect_key($item, array_flip(['order_count', 'success_count', 'failed_count', 'unfinished_count'])));
        $this->assertSame('50.00', $item['success_rate'], '还在充值的不进分母');
        $this->assertSame($p50->name, $guangdong['products'][1]['product_name']);

        $this->assertSame('广西', $guangxi['province']);
        $this->assertSame([$p50->id], array_column($guangxi['products'], 'product_id'));

        $cucc = $this->body($this->jsonRequest('GET', '/admin/product-stats/province-products?date=' . self::DAY . '&operator=cucc', $token));
        $this->assertSame(['广西'], array_column($cucc['provinces'], 'province'));
        $this->assertSame(1, $cucc['summary']['order_count']);

        $this->assertSame(403, $this->jsonRequest('GET', '/admin/product-stats/province-products', $this->loginAs($this->createAdminWithPermissions(['product.view'])))->getStatusCode());
    }

    public function testDayWithNoStats()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        $response = $this->jsonRequest('GET', '/admin/product-stats/province-products?date=2001-03-06', $token);
        $this->assertSame(200, $response->getStatusCode());
        $result = $this->body($response);
        $this->assertSame([], $result['provinces']);
        $this->assertNull($result['computed_at']);
        $this->assertSame(0, $result['summary']['order_count']);
        $this->assertNull($result['summary']['success_rate']);
    }

    private function order(Merchant $merchant, Product $product, string $province, string $operator, string $status, string $createdAt = self::DAY . ' 10:00:00'): Order
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
            'face_value' => $product->face_value,
            'sale_price' => '99.50',
            'status' => $status,
            'notify_status' => Order::NOTIFY_NONE,
        ]);
        if ($status === Order::STATUS_SUCCESS) {
            $order->update(['cost_price' => '98.00', 'profit' => '1.50']);
        }
        Order::where('id', $order->id)->update(['created_at' => $createdAt]);

        return $order;
    }

    private function stats(): ProvinceProductStatsService
    {
        return ApplicationContext::getContainer()->get(ProvinceProductStatsService::class);
    }
}
