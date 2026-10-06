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

use App\Model\ChannelMaintenance;
use App\Model\MobileBlacklist;
use HyperfTest\HttpTestCase;

/**
 * 风控：号码黑名单、通道维护（维护中的通道不参与选路）。
 *
 * @internal
 * @coversNothing
 */
class RiskControllerTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

    protected function tearDown(): void
    {
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testBlacklistAddListAndRemove()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['risk.view', 'risk.manage']));
        $prefix = '100' . random_int(10000, 99999);
        $a = $prefix . '001';
        $b = $prefix . '002';
        $this->blacklistMobiles = [$a, $b];

        $added = $this->body($this->jsonRequest('POST', '/admin/risk/blacklist', $token, ['mobiles' => "{$a}\n{$b}, {$a}", 'reason' => '投诉']));
        $this->assertSame(['added' => 2, 'skipped' => 0], $added);
        $again = $this->body($this->jsonRequest('POST', '/admin/risk/blacklist', $token, ['mobiles' => [$a]]));
        $this->assertSame(['added' => 0, 'skipped' => 1], $again);

        $list = $this->body($this->jsonRequest('GET', '/admin/risk/blacklist?mobile=' . $prefix, $token));
        $this->assertSame([$b, $a], array_column($list['data'], 'mobile'));
        $this->assertSame('投诉', $list['data'][0]['reason']);

        $id = $list['data'][0]['id'];
        $this->assertSame(200, $this->jsonRequest('DELETE', "/admin/risk/blacklist/{$id}", $token)->getStatusCode());
        $this->assertFalse(MobileBlacklist::where('mobile', $b)->exists());

        $this->assertSame(422, $this->jsonRequest('POST', '/admin/risk/blacklist', $token, ['mobiles' => '1381234'])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('POST', '/admin/risk/blacklist', $token, ['mobiles' => ''])->getStatusCode());
        $this->assertSame([], $this->body($this->jsonRequest('GET', '/admin/risk/blacklist?mobile=1009999999', $token))['data']);
    }

    public function testMaintenanceRemovesChannelFromRouting()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['risk.view', 'risk.manage']));
        $a = $this->createSupplier(['*']);
        $b = $this->createSupplier(['*']);
        $spA = $this->createSupplierProduct($a, ['cmcc', 'ctcc'], 100, '98.00');
        $spB = $this->createSupplierProduct($b, ['cmcc'], 100, '99.00');
        $product = $this->createProduct([$spA->id => 1, $spB->id => 2]);
        $gdMobile = $this->createSegment('cmcc', '广东');
        $hnMobile = $this->createSegment('cmcc', '湖南');
        $hnTelecom = $this->createSegment('ctcc', '湖南');

        $this->assertSame([$spA->id, $spB->id], $this->candidates($token, $gdMobile, $product->id));

        // A 的广东移动维护：广东移动不走 A，湖南移动、湖南电信照常
        $created = $this->jsonRequest('POST', '/admin/risk/maintenances', $token, [
            'supplier_id' => $a->id, 'operator' => 'cmcc', 'province' => '广东', 'end_at' => date('Y-m-d H:i:s', time() + 3600), 'reason' => '运营商割接',
        ]);
        $this->assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        $this->assertSame('active', $this->body($created)['state']);
        $this->assertSame([$spB->id], $this->candidates($token, $gdMobile, $product->id));
        $this->assertSame([$spA->id, $spB->id], $this->candidates($token, $hnMobile, $product->id));

        // 提前结束后恢复
        $finished = $this->body($this->jsonRequest('POST', "/admin/risk/maintenances/{$this->body($created)['id']}/finish", $token));
        $this->assertSame('ended', $finished['state']);
        $this->assertSame([$spA->id, $spB->id], $this->candidates($token, $gdMobile, $product->id));

        // 整个供应商维护：所有运营商、省份都不走 A
        $this->jsonRequest('POST', '/admin/risk/maintenances', $token, ['supplier_id' => $a->id, 'end_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->assertSame([$spB->id], $this->candidates($token, $hnMobile, $product->id));
        $this->assertSame([], $this->candidates($token, $hnTelecom, $product->id));

        // 还没开始的维护不生效
        ChannelMaintenance::where('supplier_id', $a->id)->delete();
        $this->jsonRequest('POST', '/admin/risk/maintenances', $token, [
            'supplier_id' => $a->id, 'start_at' => date('Y-m-d H:i:s', time() + 3600), 'end_at' => date('Y-m-d H:i:s', time() + 7200),
        ]);
        $this->assertSame([$spA->id, $spB->id], $this->candidates($token, $gdMobile, $product->id));
        $upcoming = $this->body($this->jsonRequest('GET', "/admin/risk/maintenances?supplier_id={$a->id}&state=upcoming", $token));
        $this->assertSame(1, $upcoming['total']);
        $this->assertSame($a->name, $upcoming['data'][0]['supplier_name']);
        $this->assertSame([], $this->body($this->jsonRequest('GET', "/admin/risk/maintenances?supplier_id={$a->id}&state=active", $token))['data']);
    }

    public function testMaintenanceValidationAndPermission()
    {
        $supplier = $this->createSupplier();
        $viewer = $this->loginAs($this->createAdminWithPermissions(['risk.view']));
        $this->assertSame(403, $this->jsonRequest('POST', '/admin/risk/maintenances', $viewer, [
            'supplier_id' => $supplier->id, 'end_at' => date('Y-m-d H:i:s', time() + 3600),
        ])->getStatusCode());
        $options = $this->body($this->jsonRequest('GET', '/admin/risk/maintenances/options', $viewer));
        $this->assertContains($supplier->id, array_column($options['suppliers'], 'id'));
        $this->assertContains('广东', $options['provinces']);

        $token = $this->loginAs($this->createAdminWithPermissions(['risk.manage']));
        $future = date('Y-m-d H:i:s', time() + 3600);
        foreach ([
            ['supplier_id' => 999999999, 'end_at' => $future],
            ['supplier_id' => $supplier->id],
            ['supplier_id' => $supplier->id, 'end_at' => date('Y-m-d H:i:s', time() - 60)],
            ['supplier_id' => $supplier->id, 'end_at' => 'not a time'],
            ['supplier_id' => $supplier->id, 'end_at' => $future, 'operator' => 'xx'],
            ['supplier_id' => $supplier->id, 'end_at' => $future, 'province' => '火星'],
        ] as $data) {
            $this->assertSame(422, $this->jsonRequest('POST', '/admin/risk/maintenances', $token, $data)->getStatusCode(), json_encode($data, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * @return list<int> 号段查询里这个商品的候选供应商商品
     */
    private function candidates(?string $token, string $mobile, int $productId): array
    {
        $products = array_column($this->body($this->jsonRequest('GET', '/admin/segments/lookup?mobile=' . $mobile, $token))['products'], null, 'id');

        return array_column($products[$productId]['candidates'], 'supplier_product_id');
    }
}
