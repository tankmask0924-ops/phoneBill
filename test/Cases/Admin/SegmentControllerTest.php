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

use App\Model\MobileSegment;
use HyperfTest\HttpTestCase;

/**
 * 号段查询与选路规则（docs/project.md 2.1、2.3）。
 *
 * @internal
 * @coversNothing
 */
class SegmentControllerTest extends HttpTestCase
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

    public function testCandidatesFollowRoutingRules()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['risk.view']));
        $guangdongOnly = $this->createSupplier(['广东']);
        $national = $this->createSupplier(['*']);
        $hunanOnly = $this->createSupplier(['湖南']);
        $stopped = $this->createSupplier(['*'], 'disabled');

        $a = $this->createSupplierProduct($guangdongOnly, ['cmcc'], 100, '98.80');
        $b = $this->createSupplierProduct($national, ['cmcc', 'cucc'], 100, '99.00');
        $cheaper = $this->createSupplierProduct($national, ['cmcc'], 100, '98.50');
        $telecom = $this->createSupplierProduct($national, ['ctcc'], 100, '98.00');
        $wrongProvince = $this->createSupplierProduct($hunanOnly, ['cmcc'], 100, '90.00');
        $offShelf = $this->createSupplierProduct($national, ['cmcc'], 100, '90.00', 'disabled');
        $ofStopped = $this->createSupplierProduct($stopped, ['cmcc'], 100, '90.00');
        $product = $this->createProduct([
            $a->id => 1, $b->id => 2, $cheaper->id => 2, $telecom->id => 1,
            $wrongProvince->id => 1, $offShelf->id => 1, $ofStopped->id => 1,
        ]);
        $guangdongOnlyProduct = $this->createProduct([$a->id => 1]);
        $offShelfProduct = $this->createProduct([$b->id => 1], 100, 'disabled');

        $result = $this->lookup($token, $this->createSegment('cmcc', '广东'));
        $this->assertNull($result['rejected_reason']);
        $this->assertSame('移动', $result['segment']['operator_name']);
        $products = array_column($result['products'], null, 'id');
        // 优先级 1 的 A；优先级 2 里成本低的在前
        $this->assertSame([$a->id, $cheaper->id, $b->id], array_column($products[$product->id]['candidates'], 'supplier_product_id'));
        $this->assertSame([$a->id], array_column($products[$guangdongOnlyProduct->id]['candidates'], 'supplier_product_id'));
        $this->assertArrayNotHasKey($offShelfProduct->id, $products, '下架的商品不列出');

        // 湖南移动：只做广东的 A 不在里面
        $products = array_column($this->lookup($token, $this->createSegment('cmcc', '湖南'))['products'], null, 'id');
        $this->assertSame([$wrongProvince->id, $cheaper->id, $b->id], array_column($products[$product->id]['candidates'], 'supplier_product_id'));
        $this->assertSame([], $products[$guangdongOnlyProduct->id]['candidates'], '没有候选，下单会被拒绝');

        // 联通 / 电信
        $products = array_column($this->lookup($token, $this->createSegment('cucc', '四川'))['products'], null, 'id');
        $this->assertSame([$b->id], array_column($products[$product->id]['candidates'], 'supplier_product_id'));
        $products = array_column($this->lookup($token, $this->createSegment('ctcc', '四川'))['products'], null, 'id');
        $this->assertSame([$telecom->id], array_column($products[$product->id]['candidates'], 'supplier_product_id'));
    }

    public function testRejectedNumbers()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['risk.view']));

        $virtual = $this->lookup($token, $this->createSegment('cmcc', '广东', true));
        $this->assertStringContainsString('虚拟运营商', $virtual['rejected_reason']);
        $this->assertSame([], $virtual['products']);

        do {
            $unknown = '100' . random_int(10000000, 99999999);
        } while (MobileSegment::where('segment', substr($unknown, 0, 7))->exists());
        $this->assertNull($this->lookup($token, $unknown)['segment']);

        foreach (['', '1381234567', '23812345678', 'abc'] as $bad) {
            $this->assertSame(422, $this->jsonRequest('GET', '/admin/segments/lookup?mobile=' . $bad, $token)->getStatusCode(), $bad);
        }
        $this->assertSame(403, $this->jsonRequest('GET', '/admin/segments/lookup?mobile=13800138000', $this->loginAs($this->createAdminWithPermissions(['product.view'])))->getStatusCode());
    }

    private function lookup(?string $token, string $mobile): array
    {
        $response = $this->jsonRequest('GET', '/admin/segments/lookup?mobile=' . $mobile, $token);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->body($response);
    }
}
