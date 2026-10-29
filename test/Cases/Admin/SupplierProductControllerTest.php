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

use App\Model\AdminOperationLog;
use App\Model\ProductRoute;
use App\Model\SupplierProduct;
use HyperfTest\HttpTestCase;

/**
 * 供应商商品：增改、按运营商筛选、被绑定后面值不能改、一键解绑。
 *
 * @internal
 * @coversNothing
 */
class SupplierProductControllerTest extends HttpTestCase
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

    public function testCreateUpdateAndList()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['supplier_product.view', 'supplier_product.manage']));
        $supplier = $this->createSupplier();

        $created = $this->jsonRequest('POST', '/admin/supplier-products', $token, [
            'supplier_id' => $supplier->id,
            'name' => '移动联通100',
            'face_value' => 100,
            'cost_price' => '98.8',
            'external_code' => 'YD100',
            'operators' => ['cucc', 'cmcc', 'cmcc'],
            'provinces' => ['广东', '湖南'],
        ]);
        $body = $this->body($created);
        $this->assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        $this->supplierProductIds[] = $body['id'];
        $this->assertSame('98.80', $body['cost_price']);
        $this->assertSame(['cmcc', 'cucc'], $body['operators']);
        $this->assertSame(['湖南', '广东'], $body['provinces'], '按省份表的顺序排');
        $this->assertSame($supplier->name, $body['supplier_name']);

        // 省份必填、要合法
        foreach ([[], ['火星'], null] as $provinces) {
            $bad = ['supplier_id' => $supplier->id, 'name' => 'x', 'face_value' => 100, 'cost_price' => '1', 'external_code' => 'X', 'operators' => ['cmcc'], 'provinces' => $provinces];
            $this->assertSame(422, $this->jsonRequest('POST', '/admin/supplier-products', $token, $bad)->getStatusCode(), json_encode($provinces));
        }

        $updated = $this->body($this->jsonRequest('PUT', "/admin/supplier-products/{$body['id']}", $token, [
            'cost_price' => 99,
            'operators' => ['ctcc'],
            'face_value' => 50,
            'provinces' => ['广东', '*'],
        ]));
        $this->assertSame(['*'], $updated['provinces'], '选了全国只保留 *');
        $this->assertSame('99.00', $updated['cost_price']);
        $this->assertSame(['ctcc'], $updated['operators']);
        $this->assertSame(50, $updated['face_value'], '还没被绑定，面值可以改');

        $list = $this->body($this->jsonRequest('GET', "/admin/supplier-products?supplier_id={$supplier->id}&operator=ctcc", $token));
        $this->assertSame([$body['id']], array_column($list['data'], 'id'));
        $none = $this->body($this->jsonRequest('GET', "/admin/supplier-products?supplier_id={$supplier->id}&operator=cmcc", $token));
        $this->assertSame([], $none['data']);
        $byProvince = $this->body($this->jsonRequest('GET', "/admin/supplier-products?supplier_id={$supplier->id}&province=" . urlencode('西藏'), $token));
        $this->assertSame([$body['id']], array_column($byProvince['data'], 'id'), '覆盖全国的按任何省份都能筛到');
        $this->assertCount(31, $this->body($this->jsonRequest('GET', '/admin/supplier-products/meta', $token))['provinces']);

        $options = $this->body($this->jsonRequest('GET', '/admin/supplier-products/supplier-options', $token));
        $this->assertContains($supplier->id, array_column($options, 'id'));
    }

    public function testUpstreamProductsMarkAddedOnesAndNeedManagePermission()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['supplier_product.view', 'supplier_product.manage']));
        $supplier = $this->createSupplier();

        $options = $this->body($this->jsonRequest('GET', '/admin/supplier-products/supplier-options', $token));
        $this->assertTrue(array_column($options, 'supports_upstream_products', 'id')[$supplier->id]);

        $created = $this->body($this->jsonRequest('POST', '/admin/supplier-products', $token, [
            'supplier_id' => $supplier->id, 'name' => '移动100', 'face_value' => 100, 'cost_price' => '98.5', 'external_code' => 'MOCK_CMCC_100', 'operators' => ['cmcc'], 'provinces' => ['*'],
        ]));
        $this->supplierProductIds[] = $created['id'];

        $response = $this->jsonRequest('GET', "/admin/supplier-products/upstream-products?supplier_id={$supplier->id}", $token);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $products = array_column($this->body($response), null, 'code');
        $this->assertSame(['MOCK_CMCC_100', 'MOCK_CUCC_100', 'MOCK_CTCC_50', 'MOCK_OFF'], array_keys($products));
        $this->assertTrue($products['MOCK_CMCC_100']['added']);
        $this->assertFalse($products['MOCK_CUCC_100']['added']);
        $this->assertSame(['cucc'], $products['MOCK_CUCC_100']['operators']);
        $this->assertFalse($products['MOCK_OFF']['on_sale']);

        // 不存在的供应商、驱动不支持
        $this->assertSame(404, $this->jsonRequest('GET', '/admin/supplier-products/upstream-products?supplier_id=999999999', $token)->getStatusCode());
        $supplier->update(['driver' => 'no_such_driver']);
        $this->assertSame(422, $this->jsonRequest('GET', "/admin/supplier-products/upstream-products?supplier_id={$supplier->id}", $token)->getStatusCode());

        // 只有查看权限：不能查
        $viewer = $this->loginAs($this->createAdminWithPermissions(['supplier_product.view']));
        $this->assertSame(403, $this->jsonRequest('GET', "/admin/supplier-products/upstream-products?supplier_id={$supplier->id}", $viewer)->getStatusCode());
    }

    public function testValidationAndBoundFaceValue()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['supplier_product.manage']));
        $supplier = $this->createSupplier();
        $valid = ['supplier_id' => $supplier->id, 'name' => 'x', 'face_value' => 100, 'cost_price' => '98', 'external_code' => 'c', 'operators' => ['cmcc']];

        foreach ([
            ['supplier_id' => 999999999],
            ['face_value' => 0],
            ['face_value' => '10.5'],
            ['cost_price' => '0'],
            ['cost_price' => '1.234'],
            ['operators' => []],
            ['operators' => ['xyz']],
            ['external_code' => ''],
        ] as $override) {
            $response = $this->jsonRequest('POST', '/admin/supplier-products', $token, array_merge($valid, $override));
            $this->assertSame(422, $response->getStatusCode(), json_encode($override));
        }

        $sp = $this->createSupplierProduct($supplier, ['cmcc']);
        $this->createProduct([$sp->id => 1]);
        $this->assertSame(422, $this->jsonRequest('PUT', "/admin/supplier-products/{$sp->id}", $token, ['face_value' => 50])->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('PUT', "/admin/supplier-products/{$sp->id}", $token, ['face_value' => 100, 'cost_price' => '97.5'])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('PUT', "/admin/supplier-products/{$sp->id}", $token, ['supplier_id' => $this->createSupplier()->id])->getStatusCode());
        $this->assertSame('disabled', $this->body($this->jsonRequest('POST', "/admin/supplier-products/{$sp->id}/status", $token, ['status' => 'disabled']))['status']);
    }

    public function testUnbindFromAllProducts()
    {
        $supplier = $this->createSupplier();
        $sp = $this->createSupplierProduct($supplier, ['cmcc']);
        $other = $this->createSupplierProduct($supplier, ['cmcc']);
        $shared = $this->createProduct([$sp->id => 2, $other->id => 1]);
        $only = $this->createProduct([$sp->id => 5]);
        $untouched = $this->createProduct([$other->id => 1]);
        $viewer = $this->loginAs($this->createAdminWithPermissions(['supplier_product.view', 'supplier_product.manage']));
        $manager = $this->loginAs($this->createAdminWithPermissions(['supplier_product.view', 'product.manage']));

        $bindings = $this->body($this->jsonRequest('GET', "/admin/supplier-products/{$sp->id}/bindings", $viewer));
        $this->assertSame([[$shared->id, 2, 1], [$only->id, 5, 0]], array_map(static fn (array $b) => [$b['product_id'], $b['priority'], $b['remaining_routes']], $bindings));
        $this->assertSame($shared->code, $bindings[0]['code']);
        $this->assertSame([], $this->body($this->jsonRequest('GET', "/admin/supplier-products/{$this->createSupplierProduct($supplier, ['cmcc'])->id}/bindings", $viewer)));
        $this->assertSame(404, $this->jsonRequest('GET', '/admin/supplier-products/999999999/bindings', $viewer)->getStatusCode());

        // 改的是平台商品配置，要 product.manage
        $this->assertSame(403, $this->jsonRequest('DELETE', "/admin/supplier-products/{$sp->id}/bindings", $viewer)->getStatusCode());
        $this->assertSame(['unbound' => 2], $this->body($this->jsonRequest('DELETE', "/admin/supplier-products/{$sp->id}/bindings", $manager)));
        $this->assertSame(0, ProductRoute::where('supplier_product_id', $sp->id)->count());
        $this->assertSame([$other->id], ProductRoute::where('product_id', $shared->id)->pluck('supplier_product_id')->all());
        $this->assertSame(1, ProductRoute::where('product_id', $untouched->id)->count());
        $log = AdminOperationLog::where('action', 'unbind_supplier_product')->where('target_id', $shared->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals([['supplier_product_id' => $other->id, 'priority' => 1]], $log->after_data['routes']);
        $this->assertSame(['unbound' => 0], $this->body($this->jsonRequest('DELETE', "/admin/supplier-products/{$sp->id}/bindings", $manager)), '再点一次没有可解绑的');

        // 解绑后可以删除
        $this->assertSame(200, $this->jsonRequest('DELETE', "/admin/supplier-products/{$sp->id}", $viewer)->getStatusCode());
    }

    public function testSyncCostPricesFromUpstream()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['supplier_product.view', 'supplier_product.manage']));
        $supplier = $this->createSupplier();
        $make = function (string $code, string $cost) use ($supplier) {
            $sp = $this->createSupplierProduct($supplier, ['cmcc'], 100, $cost);
            $sp->update(['external_code' => $code]);

            return $sp;
        };
        // 模拟上游：MOCK_CMCC_100 98.50、MOCK_CUCC_100 98.80、MOCK_OFF 9.90（已下架）
        $cheaper = $make('MOCK_CMCC_100', '97.00');
        $same = $make('MOCK_CUCC_100', '98.80');
        $off = $make('MOCK_OFF', '9.00');
        $gone = $make('NOT_UPSTREAM', '50.00');
        $url = "/admin/supplier-products/sync-cost?supplier_id={$supplier->id}";

        // 预览：只算不改
        $preview = $this->body($this->jsonRequest('GET', $url, $token));
        $this->assertFalse($preview['applied']);
        $this->assertSame([[$cheaper->id, '97.00', '98.50', true], [$off->id, '9.00', '9.90', false]], array_map(static fn (array $c) => [$c['id'], $c['cost_price'], $c['new_cost_price'], $c['upstream_on_sale']], $preview['changed']));
        $this->assertSame(1, $preview['unchanged_count']);
        $this->assertSame([$gone->id], array_column($preview['missing'], 'id'), '上游没有这个编码的不改');
        $this->assertSame([], $preview['no_price']);
        $this->assertSame('97.00', SupplierProduct::find($cheaper->id)->cost_price);

        // 确认同步：保存、记一条操作日志
        $synced = $this->body($this->jsonRequest('POST', '/admin/supplier-products/sync-cost', $token, ['supplier_id' => $supplier->id]));
        $this->assertTrue($synced['applied']);
        $this->assertSame(['98.50', '98.80', '9.90', '50.00'], [SupplierProduct::find($cheaper->id)->cost_price, SupplierProduct::find($same->id)->cost_price, SupplierProduct::find($off->id)->cost_price, SupplierProduct::find($gone->id)->cost_price]);
        $log = AdminOperationLog::where('action', 'sync_supplier_product_cost')->where('target_id', $supplier->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals(['from' => '97.00', 'to' => '98.50'], array_intersect_key($log->after_data['changes'][0], ['from' => 0, 'to' => 0]));
        $this->assertSame(0, AdminOperationLog::where('admin_user_id', $log->admin_user_id)->where('action', 'sync_cost')->count(), '手动记过，兜底的不会再自动多记一条');

        // 再同步一次：都一致了
        $again = $this->body($this->jsonRequest('POST', '/admin/supplier-products/sync-cost', $token, ['supplier_id' => $supplier->id]));
        $this->assertSame([false, [], 3], [$again['applied'], $again['changed'], $again['unchanged_count']]);

        // 不存在的供应商、驱动不支持查商品、只有查看权限
        $this->assertSame(404, $this->jsonRequest('GET', '/admin/supplier-products/sync-cost?supplier_id=999999999', $token)->getStatusCode());
        $viewer = $this->loginAs($this->createAdminWithPermissions(['supplier_product.view']));
        $this->assertSame(403, $this->jsonRequest('POST', '/admin/supplier-products/sync-cost', $viewer, ['supplier_id' => $supplier->id])->getStatusCode());
        $supplier->update(['driver' => 'no_such_driver']);
        $this->assertSame(422, $this->jsonRequest('GET', $url, $token)->getStatusCode());
    }

    public function testListFiltersNationwideAndSortsByCost()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['supplier_product.view']));
        $supplier = $this->createSupplier();
        $cheapAll = $this->createSupplierProduct($supplier, ['cmcc'], 100, '97.50', 'active', ['*']);
        $dearAll = $this->createSupplierProduct($supplier, ['cmcc'], 100, '99.10', 'active', ['*']);
        $guangdong = $this->createSupplierProduct($supplier, ['cmcc'], 100, '98.20', 'active', ['广东', '广西']);
        $ids = static fn (array $body) => array_column($body['data'], 'id');

        $all = $this->body($this->jsonRequest('GET', "/admin/supplier-products?supplier_id={$supplier->id}&nationwide=1", $token));
        $this->assertSame([$cheapAll->id, $dearAll->id], $ids($all), '只看全国');
        $byProvince = $this->body($this->jsonRequest('GET', "/admin/supplier-products?supplier_id={$supplier->id}&nationwide=0", $token));
        $this->assertSame([$guangdong->id], $ids($byProvince), '只看非全国');

        $asc = $this->body($this->jsonRequest('GET', "/admin/supplier-products?supplier_id={$supplier->id}&cost_sort=asc", $token));
        $this->assertSame([$cheapAll->id, $guangdong->id, $dearAll->id], $ids($asc));
        $desc = $this->body($this->jsonRequest('GET', "/admin/supplier-products?supplier_id={$supplier->id}&cost_sort=desc", $token));
        $this->assertSame([$dearAll->id, $guangdong->id, $cheapAll->id], $ids($desc));
        $this->assertSame(3, $this->body($this->jsonRequest('GET', "/admin/supplier-products?supplier_id={$supplier->id}&cost_sort=bad&nationwide=x", $token))['total'], '不认识的值不筛选、不排序');
    }

    public function testListWithNoMatches()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['supplier_product.view']));
        $response = $this->jsonRequest('GET', '/admin/supplier-products?keyword=' . $this->uniq('none_'), $token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->body($response)['data']);
    }
}
