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
