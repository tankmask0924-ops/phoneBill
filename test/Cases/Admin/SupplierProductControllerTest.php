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

use HyperfTest\HttpTestCase;

/**
 * 供应商商品：增改、按运营商筛选、被绑定后面值不能改。
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
        ]);
        $body = $this->body($created);
        $this->assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        $this->supplierProductIds[] = $body['id'];
        $this->assertSame('98.80', $body['cost_price']);
        $this->assertSame(['cmcc', 'cucc'], $body['operators']);
        $this->assertSame($supplier->name, $body['supplier_name']);

        $updated = $this->body($this->jsonRequest('PUT', "/admin/supplier-products/{$body['id']}", $token, [
            'cost_price' => 99,
            'operators' => ['ctcc'],
            'face_value' => 50,
        ]));
        $this->assertSame('99.00', $updated['cost_price']);
        $this->assertSame(['ctcc'], $updated['operators']);
        $this->assertSame(50, $updated['face_value'], '还没被绑定，面值可以改');

        $list = $this->body($this->jsonRequest('GET', "/admin/supplier-products?supplier_id={$supplier->id}&operator=ctcc", $token));
        $this->assertSame([$body['id']], array_column($list['data'], 'id'));
        $none = $this->body($this->jsonRequest('GET', "/admin/supplier-products?supplier_id={$supplier->id}&operator=cmcc", $token));
        $this->assertSame([], $none['data']);

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
            'supplier_id' => $supplier->id, 'name' => '移动100', 'face_value' => 100, 'cost_price' => '98.5', 'external_code' => 'MOCK_CMCC_100', 'operators' => ['cmcc'],
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

    public function testListWithNoMatches()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['supplier_product.view']));
        $response = $this->jsonRequest('GET', '/admin/supplier-products?keyword=' . $this->uniq('none_'), $token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->body($response)['data']);
    }
}
