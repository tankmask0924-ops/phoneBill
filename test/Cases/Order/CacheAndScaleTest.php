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

namespace HyperfTest\Cases\Order;

use App\Model\MerchantBalanceLog;
use App\Model\MobileBlacklist;
use App\Model\MobileSegment;
use App\Model\Order;
use App\Network\HttpClient;
use App\Service\Admin\BlacklistAdminService;
use App\Service\Admin\MaintenanceAdminService;
use App\Service\Admin\MerchantProductAdminService;
use App\Service\Admin\ProductAdminService;
use App\Service\Admin\SupplierAdminService;
use App\Service\Admin\SupplierProductAdminService;
use App\Service\Mobile\MobileSegmentService;
use App\Service\Order\OrderDispatcher;
use App\Service\Order\OrderNoGenerator;
use App\Service\Order\OrderService;
use App\Service\Product\ProductRouteService;
use App\Service\Risk\BlacklistService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Redis\Redis;
use Hyperf\Testing\Client;
use HyperfTest\Cases\Admin\CreatesAdmins;
use HyperfTest\Cases\Admin\CreatesCatalog;
use HyperfTest\HttpTestCase;
use HyperfTest\Support\FakeHttpClient;
use HyperfTest\Support\FakeOrderDispatcher;

use function Hyperf\Support\make;

/**
 * 缓存一改就失效（号段、商户、商户价格、选路、黑名单）、维护按时间生效、订单号撞号重试，以及大表列表默认只查最近 7 天。
 *
 * @internal
 * @coversNothing
 */
class CacheAndScaleTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

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
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testSegmentCacheIsClearedWhenSegmentChanges()
    {
        $service = make(MobileSegmentService::class);
        do {
            $segment = '100' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (MobileSegment::where('segment', $segment)->exists());

        $this->assertNull($service->identify($segment . '0000'), '查不到也会缓存');
        $row = MobileSegment::create(['segment' => $segment, 'operator' => 'cmcc', 'province' => '广东', 'is_virtual' => false]);
        $this->segmentIds[] = $row->id;
        $this->assertSame('广东', $service->identify($segment . '0000')?->province, '新增号段后立即能查到');

        $row->update(['province' => '湖南']);
        $this->assertSame('湖南', $service->identify($segment . '0000')->province);
        $this->assertFalse($service->identify($segment . '0000')->is_virtual);
    }

    public function testPriceChangeAppliesToTheNextOrder()
    {
        $sp = $this->createSupplierProduct($this->createSupplier(), ['cmcc'], 100, '98.00');
        $product = $this->createProduct([$sp->id => 1]);
        $merchant = $this->createMerchant('500.00');
        $this->openProduct($merchant, $product, ['cmcc' => '99.50']);
        $orders = make(OrderService::class);

        $first = $orders->create($merchant, ['product_code' => $product->code, 'mobile' => $this->createSegment('cmcc', '广东'), 'merchant_order_no' => 'P1']);
        $this->assertSame('99.50', $first->sale_price);

        // 后台改价：事务提交后清缓存，下一单立即按新价格
        make(MerchantProductAdminService::class)->save($this->createSuperAdmin(), $merchant->id, $product->id, ['prices' => ['cmcc' => '99.80']], null);
        $second = $orders->create($merchant, ['product_code' => $product->code, 'mobile' => $this->createSegment('cmcc', '广东'), 'merchant_order_no' => 'P2']);
        $this->assertSame('99.80', $second->sale_price);
    }

    public function testOrderNoCollisionIsRetriedWithANewNumber()
    {
        $sp = $this->createSupplierProduct($this->createSupplier(), ['cmcc'], 100, '98.00');
        $product = $this->createProduct([$sp->id => 1]);
        $merchant = $this->createMerchant('500.00');
        $this->openProduct($merchant, $product, ['cmcc' => '99.50']);
        $taken = $this->order($merchant->id, $product->id, Order::STATUS_SUCCESS, 0)->order_no;
        $fresh = date('YmdHis') . '99999999';

        // 第一次给出已经被占用的单号，第二次给新号
        ApplicationContext::getContainer()->set(OrderNoGenerator::class, new class([$taken, $fresh]) extends OrderNoGenerator {
            public function __construct(private array $numbers)
            {
            }

            public function next(): string
            {
                return array_shift($this->numbers);
            }
        });
        $order = make(OrderService::class)->create($merchant, ['product_code' => $product->code, 'mobile' => $this->createSegment('cmcc', '广东'), 'merchant_order_no' => 'C1']);

        $this->assertSame($fresh, $order->order_no);
        $this->assertSame(1, MerchantBalanceLog::where('order_id', $order->id)->count(), '只扣了一次款');
    }

    public function testRouteCacheFollowsAdminChanges()
    {
        $admin = $this->createSuperAdmin();
        $a = $this->createSupplier();
        $b = $this->createSupplier();
        $spA = $this->createSupplierProduct($a, ['cmcc'], 100, '98.00');
        $spB = $this->createSupplierProduct($b, ['cmcc'], 100, '99.00');
        $product = $this->createProduct([$spA->id => 1, $spB->id => 2]);
        $routes = make(ProductRouteService::class);
        $ids = fn () => array_column($routes->candidates([$product->id], 'cmcc', '广东')[$product->id], 'supplier_product_id');

        $this->assertSame([$spA->id, $spB->id], $ids(), '第一次查完已经进了缓存');

        make(SupplierAdminService::class)->changeStatus($admin, $a->id, 'disabled', null);
        $this->assertSame([$spB->id], $ids(), '停用供应商立即生效');

        make(SupplierAdminService::class)->changeStatus($admin, $a->id, 'active', null);
        make(SupplierProductAdminService::class)->update($admin, $spB->id, ['cost_price' => '97.00'], null);
        make(ProductAdminService::class)->update($admin, $product->id, ['routes' => [
            ['supplier_product_id' => $spA->id, 'priority' => 1],
            ['supplier_product_id' => $spB->id, 'priority' => 1],
        ]], null);
        $this->assertSame([$spB->id, $spA->id], $ids(), '改绑定和成本后按新的优先级、成本排序');

        make(SupplierAdminService::class)->update($admin, $b->id, ['provinces' => ['湖南']], null);
        $this->assertSame([$spA->id], $ids(), '改覆盖省份立即生效');
    }

    public function testMaintenanceStartsOnTimeWithoutClearingCache()
    {
        $a = $this->createSupplier();
        $spA = $this->createSupplierProduct($a, ['cmcc'], 100, '98.00');
        $product = $this->createProduct([$spA->id => 1]);
        $routes = make(ProductRouteService::class);
        $ids = fn () => array_column($routes->candidates([$product->id], 'cmcc', '广东')[$product->id], 'supplier_product_id');

        make(MaintenanceAdminService::class)->create($this->createSuperAdmin(), [
            'supplier_id' => $a->id,
            'start_at' => date('Y-m-d H:i:s', time() + 2),
            'end_at' => date('Y-m-d H:i:s', time() + 3600),
        ], null);
        $this->assertSame([$spA->id], $ids(), '还没到维护时间');
        sleep(3);
        $this->assertSame([], $ids(), '到点自动生效，中间没有任何清缓存');
    }

    public function testBlacklistCacheFollowsAdminChanges()
    {
        $admin = $this->createSuperAdmin();
        $mobile = '100' . random_int(10000000, 99999999);
        $this->blacklistMobiles[] = $mobile;
        $service = make(BlacklistService::class);

        $this->assertFalse($service->isBlocked($mobile));
        make(BlacklistAdminService::class)->create($admin, ['mobiles' => $mobile, 'reason' => '投诉'], null);
        $this->assertTrue($service->isBlocked($mobile), '加入后立即生效');

        // 查询走的是 Redis 集合：绕过模型直接删库（不触发失效）也还是命中
        MobileBlacklist::query()->getQuery()->where('mobile', $mobile)->delete();
        $this->assertTrue($service->isBlocked($mobile), '没有查库');
        MobileBlacklist::create(['mobile' => $mobile, 'reason' => '投诉']);

        // Redis 被清空：重建后照样拦住，不会放过
        $redis = ApplicationContext::getContainer()->get(Redis::class);
        $redis->del('blacklist:mobiles', 'blacklist:built_version');
        $this->assertTrue($service->isBlocked($mobile), 'Redis 清空后从数据库重建');

        $id = MobileBlacklist::where('mobile', $mobile)->value('id');
        make(BlacklistAdminService::class)->delete($admin, $id, null);
        $this->assertFalse($service->isBlocked($mobile), '移出后立即生效');
    }

    public function testListsDefaultToRecentSevenDays()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'merchant.view']));
        $sp = $this->createSupplierProduct($this->createSupplier(), ['cmcc'], 100, '98.00');
        $product = $this->createProduct([$sp->id => 1]);
        $merchant = $this->createMerchant('500.00');
        $recent = $this->order($merchant->id, $product->id, Order::STATUS_SUCCESS, 0);
        $old = $this->order($merchant->id, $product->id, Order::STATUS_SUCCESS, 10);
        $oldAbnormal = $this->order($merchant->id, $product->id, Order::STATUS_ABNORMAL, 10);

        $ids = fn (string $query) => array_column($this->body($this->jsonRequest('GET', "/admin/orders?merchant_id={$merchant->id}{$query}", $token))['data'], 'id');
        $this->assertSame([$recent->id], $ids(''), '默认只看最近 7 天');
        $this->assertContains($old->id, $ids('&created_from=' . date('Y-m-d', strtotime('-30 days'))), '指定日期能看到更早的');
        $this->assertSame([$old->id], $ids('&keyword=' . $old->order_no), '按单号精确查不限时间');
        $this->assertSame([$oldAbnormal->id], $ids('&status=abnormal'), '异常订单不限时间');
    }

    private function order(int $merchantId, int $productId, string $status, int $daysAgo): Order
    {
        $order = Order::create([
            'order_no' => date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchantId,
            'merchant_order_no' => $this->uniq('M'),
            'product_id' => $productId,
            'product_code' => 'P',
            'product_name' => '话费100',
            'mobile' => '1009' . random_int(1000000, 9999999),
            'operator' => 'cmcc',
            'province' => '广东',
            'face_value' => 100,
            'sale_price' => '99.50',
            'status' => $status,
            'notify_status' => 'none',
        ]);
        Order::where('id', $order->id)->update(['created_at' => date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"))]);

        return $order;
    }
}
