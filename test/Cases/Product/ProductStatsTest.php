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
use App\Model\ProductDailyStat;
use App\Network\HttpClient;
use App\Service\Order\OrderDispatcher;
use App\Service\Product\ProductStatsService;
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
class ProductStatsTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

    /** 统计按整天覆盖写，用一个不会有真实订单的日期，免得和开发库里的数据互相影响 */
    private const DAY = '2001-02-03';

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
        ProductDailyStat::whereIn('product_id', $this->productIds)->delete();
        ProductDailyStat::where('stat_date', self::DAY)->delete();
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testComputesSuccessRateAndDurationsPerOperator()
    {
        [$merchant, $product] = $this->catalog();
        foreach ([40, 10, 100, 30, 20] as $seconds) {
            $this->order($merchant, $product, 'cmcc', Order::STATUS_SUCCESS, self::DAY . ' 10:00:00', $seconds);
        }
        $this->order($merchant, $product, 'cmcc', Order::STATUS_FAILED, self::DAY . ' 11:00:00', 5);
        $this->order($merchant, $product, 'cmcc', Order::STATUS_PROCESSING, self::DAY . ' 23:59:59');
        $this->order($merchant, $product, 'cmcc', Order::STATUS_ABNORMAL, self::DAY . ' 00:00:00');
        $this->order($merchant, $product, 'cucc', Order::STATUS_FAILED, self::DAY . ' 12:00:00', 8);
        // 前一天下的单、当天才完成：算在前一天
        $this->order($merchant, $product, 'cmcc', Order::STATUS_SUCCESS, '2001-02-02 23:59:00', 120);

        $this->assertSame(2, $this->stats()->compute(self::DAY));
        $this->assertSame(2, $this->stats()->compute(self::DAY), '重算覆盖，不会多出行');

        $cmcc = ProductDailyStat::where(['stat_date' => self::DAY, 'product_id' => $product->id, 'operator' => 'cmcc'])->first();
        $this->assertSame(8, $cmcc->order_count);
        $this->assertSame(5, $cmcc->success_count);
        $this->assertSame(1, $cmcc->failed_count);
        $this->assertSame(2, $cmcc->unfinished_count, '处理中、异常不进成功率的分母');
        $this->assertSame('83.33', $cmcc->success_rate);
        $this->assertSame(200, $cmcc->total_duration);
        $this->assertSame(40, $cmcc->avg_duration);
        $this->assertSame(30, $cmcc->p50_duration);
        $this->assertSame(100, $cmcc->p90_duration);

        $cucc = ProductDailyStat::where(['stat_date' => self::DAY, 'product_id' => $product->id, 'operator' => 'cucc'])->first();
        $this->assertSame('0.00', $cucc->success_rate);
        $this->assertNull($cucc->avg_duration, '没有成功单就没有耗时');

        $token = $this->loginAs($this->createAdminWithPermissions(['product.view']));
        $list = $this->body($this->jsonRequest('GET', '/admin/product-stats?date_from=' . self::DAY . '&date_to=' . self::DAY . '&product_id=' . $product->id, $token));
        $this->assertSame(2, $list['total']);
        $this->assertSame($product->name, $list['data'][0]['product_name']);
        $this->assertSame(['order_count' => 9, 'success_count' => 5, 'failed_count' => 2, 'success_rate' => '71.42', 'avg_duration' => 40, 'unfinished_count' => 2], $list['summary']);

        $operatorOnly = $this->body($this->jsonRequest('GET', '/admin/product-stats?date_from=' . self::DAY . '&product_id=' . $product->id . '&operator=cucc', $token));
        $this->assertSame(['cucc'], array_column($operatorOnly['data'], 'operator'));

        $this->assertSame(403, $this->jsonRequest('GET', '/admin/product-stats', $this->loginAs($this->createAdminWithPermissions(['order.view'])))->getStatusCode());
    }

    public function testProductListShowsYesterdayAndOrdersShowDuration()
    {
        [$merchant, $product] = $this->catalog();
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        foreach ([['cmcc', 9, 1, 90], ['cucc', 1, 1, 30]] as [$operator, $success, $failed, $total]) {
            ProductDailyStat::create([
                'stat_date' => $yesterday, 'product_id' => $product->id, 'operator' => $operator,
                'order_count' => $success + $failed, 'success_count' => $success, 'failed_count' => $failed, 'unfinished_count' => 0,
                'success_rate' => ProductStatsService::rate($success, $failed), 'total_duration' => $total,
                'avg_duration' => intdiv($total, $success), 'p50_duration' => null, 'p90_duration' => null, 'computed_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $token = $this->loginAs($this->createAdminWithPermissions(['product.view', 'order.view']));
        $products = $this->body($this->jsonRequest('GET', '/admin/products?keyword=' . $product->code, $token));
        $this->assertSame(
            ['order_count' => 12, 'success_count' => 10, 'failed_count' => 2, 'success_rate' => '83.33', 'avg_duration' => 12],
            $products['data'][0]['yesterday_stats'],
            '各运营商合起来，平均耗时按成功单数加权'
        );

        $order = $this->order($merchant, $product, 'cmcc', Order::STATUS_SUCCESS, date('Y-m-d H:i:s', time() - 100), 75);
        $orders = $this->body($this->jsonRequest('GET', '/admin/orders?keyword=' . $order->order_no, $token));
        $this->assertSame(75, $orders['data'][0]['duration']);
        $pending = $this->order($merchant, $product, 'cmcc', Order::STATUS_PROCESSING, date('Y-m-d H:i:s'));
        $orders = $this->body($this->jsonRequest('GET', '/admin/orders?keyword=' . $pending->order_no, $token));
        $this->assertNull($orders['data'][0]['duration']);
    }

    /**
     * @return array{0: Merchant, 1: Product}
     */
    private function catalog(): array
    {
        $supplierProduct = $this->createSupplierProduct($this->createSupplier(), ['cmcc', 'cucc']);

        return [$this->createMerchant('100.00'), $this->createProduct([$supplierProduct->id => 1])];
    }

    /**
     * @param null|int $seconds 下单到出结果的秒数，null 表示还没结果
     */
    private function order(Merchant $merchant, Product $product, string $operator, string $status, string $createdAt, ?int $seconds = null): Order
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
            'province' => '广东',
            'face_value' => 100,
            'sale_price' => '99.50',
            'status' => $status,
            'notify_status' => Order::NOTIFY_NONE,
        ]);
        Order::where('id', $order->id)->update([
            'created_at' => $createdAt,
            'finished_at' => $seconds === null ? null : date('Y-m-d H:i:s', strtotime($createdAt) + $seconds),
        ]);

        return $order;
    }

    private function stats(): ProductStatsService
    {
        return ApplicationContext::getContainer()->get(ProductStatsService::class);
    }
}
