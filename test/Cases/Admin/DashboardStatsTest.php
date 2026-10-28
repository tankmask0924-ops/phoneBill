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

namespace HyperfTest\Cases\Admin;

use App\Model\ProvinceDailyStat;
use App\Model\ProvinceProductDailyStat;
use App\Network\HttpClient;
use App\Service\Order\OrderDispatcher;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\HttpTestCase;
use HyperfTest\Support\FakeHttpClient;
use HyperfTest\Support\FakeOrderDispatcher;

use function Hyperf\Support\make;

/**
 * @internal
 * @coversNothing
 */
class DashboardStatsTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

    /** 统计表按日期查，用不会有真实数据的日期，也和其他统计测试错开 */
    private const DAYS = ['2001-04-01', '2001-04-02', '2001-04-03'];

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
        ProvinceDailyStat::whereIn('stat_date', self::DAYS)->delete();
        ProvinceProductDailyStat::whereIn('stat_date', self::DAYS)->delete();
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testSumsDailyStatsInRange()
    {
        $a = $this->createSupplier();
        $b = $this->createSupplier();
        $p100 = $this->createProduct([], 100);
        $p50 = $this->createProduct([], 50);

        // 04-01 广东移动 A：3 单（成功 2、失败 1）；04-03 广东联通 B：2 单（成功 1、还在充值 1）；04-03 省份未知、没提交供应商：1 单失败
        $this->provinceRow('2001-04-01', '广东', 'cmcc', $a->id, 3, 2, 1, '199.00', '3.00');
        $this->provinceRow('2001-04-03', '广东', 'cucc', $b->id, 2, 1, 0, '49.50', '0.80');
        $this->provinceRow('2001-04-03', '', 'cucc', 0, 1, 0, 1, '0', '0');
        // 同一批订单按商品分：100 元 3 单，50 元 3 单
        $this->productRow('2001-04-01', '广东', 'cmcc', $p100->id, 3, 2, 1, '199.00', '3.00');
        $this->productRow('2001-04-03', '广东', 'cucc', $p50->id, 2, 1, 0, '49.50', '0.80');
        $this->productRow('2001-04-03', '', 'cucc', $p50->id, 1, 0, 1, '0', '0');
        // 范围外的不算
        $this->provinceRow('2001-03-31', '广东', 'cmcc', $a->id, 9, 9, 0, '900.00', '9.00');

        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        $result = $this->body($this->jsonRequest('GET', '/admin/dashboard/stats?date_from=2001-04-01&date_to=2001-04-03', $token));
        ProvinceDailyStat::where('stat_date', '2001-03-31')->delete();

        $this->assertSame('2001-04-01', $result['date_from']);
        $this->assertSame('2001-04-03', $result['date_to']);
        $this->assertNotNull($result['computed_at']);
        $this->assertSame([
            'order_count' => 6, 'success_count' => 3, 'failed_count' => 2, 'unfinished_count' => 1,
            'success_rate' => '60.00', 'success_amount' => '248.50', 'profit' => '3.80',
        ], $result['summary']);

        $this->assertSame(['2001-04-03', '2001-04-02', '2001-04-01'], array_column($result['days'], 'stat_date'), '从新到旧，没数据的日子补 0');
        $this->assertSame([3, 0, 3], array_column($result['days'], 'order_count'));
        $this->assertNull($result['days'][1]['success_rate']);

        $this->assertSame([$a->id, $b->id, 0], array_column($result['suppliers'], 'supplier_id'), '按订单数从多到少');
        $this->assertSame($a->name, $result['suppliers'][0]['supplier_name']);
        $this->assertNull($result['suppliers'][2]['supplier_name']);

        $this->assertSame([$p100->id, $p50->id], array_column($result['products'], 'product_id'));
        $this->assertSame($p50->code, $result['products'][1]['product_code']);
        $this->assertSame(50, $result['products'][1]['face_value']);
        $this->assertSame(3, $result['products'][1]['order_count'], '同一商品跨省份、运营商合起来');

        $this->assertSame(['广东', '未知'], array_column($result['provinces'], 'province'));
        $this->assertSame(5, $result['provinces'][0]['order_count']);

        $cucc = $this->body($this->jsonRequest('GET', '/admin/dashboard/stats?date_from=2001-04-01&date_to=2001-04-03&operator=cucc', $token));
        $this->assertSame(3, $cucc['summary']['order_count']);
        $this->assertSame([$p50->id], array_column($cucc['products'], 'product_id'));
    }

    public function testRangeWithNoStats()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        $response = $this->jsonRequest('GET', '/admin/dashboard/stats?date_from=2001-04-01&date_to=2001-04-02', $token);
        $this->assertSame(200, $response->getStatusCode());
        $result = $this->body($response);
        $this->assertNull($result['computed_at']);
        $this->assertSame(0, $result['summary']['order_count']);
        $this->assertSame('0.00', $result['summary']['success_amount']);
        $this->assertCount(2, $result['days']);
        $this->assertSame([], $result['suppliers']);
        $this->assertSame([], $result['products']);
        $this->assertSame([], $result['provinces']);
    }

    public function testDefaultsAndValidation()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        $result = $this->body($this->jsonRequest('GET', '/admin/dashboard/stats', $token));
        $this->assertSame(date('Y-m-d'), $result['date_to']);
        $this->assertSame(date('Y-m-d', strtotime('-6 days')), $result['date_from'], '默认最近 7 天，含今天');
        $this->assertCount(7, $result['days']);

        $this->assertSame(422, $this->jsonRequest('GET', '/admin/dashboard/stats?date_from=2001-04-03&date_to=2001-04-01', $token)->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('GET', '/admin/dashboard/stats?date_from=2001-01-01&date_to=2001-04-03', $token)->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('GET', '/admin/dashboard/stats?date_from=2001-01-01&date_to=2001-04-02', $token)->getStatusCode(), '正好 92 天');

        $this->assertSame(403, $this->jsonRequest('GET', '/admin/dashboard/stats', $this->loginAs($this->createAdminWithPermissions(['product.view'])))->getStatusCode());
    }

    private function provinceRow(string $date, string $province, string $operator, int $supplierId, int $orders, int $success, int $failed, string $amount, string $profit): void
    {
        ProvinceDailyStat::create($this->row($date, $province, $operator, $orders, $success, $failed, $amount, $profit) + ['supplier_id' => $supplierId]);
    }

    private function productRow(string $date, string $province, string $operator, int $productId, int $orders, int $success, int $failed, string $amount, string $profit): void
    {
        ProvinceProductDailyStat::create($this->row($date, $province, $operator, $orders, $success, $failed, $amount, $profit) + ['product_id' => $productId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $date, string $province, string $operator, int $orders, int $success, int $failed, string $amount, string $profit): array
    {
        return [
            'stat_date' => $date,
            'province' => $province,
            'operator' => $operator,
            'order_count' => $orders,
            'success_count' => $success,
            'failed_count' => $failed,
            'unfinished_count' => $orders - $success - $failed,
            'success_amount' => $amount,
            'profit' => $profit,
            'computed_at' => $date . ' 23:00:00',
        ];
    }
}
