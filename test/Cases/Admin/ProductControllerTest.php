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
 * 平台商品：绑定供应商商品、覆盖运营商、按运营商的默认售价、提醒。
 *
 * @internal
 * @coversNothing
 */
class ProductControllerTest extends HttpTestCase
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

    public function testCreateWithRoutesAndPrices()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['product.view', 'product.manage']));
        $supplier = $this->createSupplier();
        $mobileUnicom = $this->createSupplierProduct($supplier, ['cmcc', 'cucc'], 100, '99.00');
        $telecom = $this->createSupplierProduct($supplier, ['ctcc'], 100, '98.90');
        $code = $this->uniq('P');

        $created = $this->jsonRequest('POST', '/admin/products', $token, [
            'code' => $code,
            'name' => '新话费100',
            'face_value' => 100,
            'routes' => [
                ['supplier_product_id' => $mobileUnicom->id, 'priority' => 2],
                ['supplier_product_id' => $telecom->id, 'priority' => 1],
            ],
            'prices' => ['cmcc' => '99.5', 'ctcc' => '98.50', 'cucc' => ''],
        ]);
        $body = $this->body($created);
        $this->assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        $this->productIds[] = $body['id'];
        $this->assertSame(['cmcc', 'cucc', 'ctcc'], $body['operators']);
        $this->assertSame(['cmcc' => '99.50', 'ctcc' => '98.50'], $body['prices']);
        $this->assertSame([$telecom->id, $mobileUnicom->id], array_column($body['routes'], 'supplier_product_id'), '按优先级排');
        $this->assertCount(1, $body['warnings'], '电信默认售价低于成本价');
        $this->assertStringContainsString('电信', $body['warnings'][0]);

        // 去掉电信的绑定：电信的默认售价跟着去掉
        $updated = $this->body($this->jsonRequest('PUT', "/admin/products/{$body['id']}", $token, [
            'routes' => [['supplier_product_id' => $mobileUnicom->id, 'priority' => 1]],
        ]));
        $this->assertSame(['cmcc', 'cucc'], $updated['operators']);
        $this->assertSame(['cmcc' => '99.50'], $updated['prices']);
        $this->assertSame([], $updated['warnings']);

        $list = $this->body($this->jsonRequest('GET', '/admin/products?keyword=' . $code, $token));
        $this->assertSame([$body['id']], array_column($list['data'], 'id'));

        $options = $this->body($this->jsonRequest('GET', '/admin/products/supplier-product-options?face_value=100', $token));
        $this->assertContains($telecom->id, array_column($options, 'id'));
        $this->assertNotContains($this->createSupplierProduct($supplier, ['cmcc'], 50)->id, array_column(
            $this->body($this->jsonRequest('GET', '/admin/products/supplier-product-options?face_value=100', $token)),
            'id'
        ));
    }

    public function testValidation()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['product.manage']));
        $supplier = $this->createSupplier();
        $mobile = $this->createSupplierProduct($supplier, ['cmcc'], 100);
        $fifty = $this->createSupplierProduct($supplier, ['cmcc'], 50);
        $existing = $this->createProduct([$mobile->id => 1]);
        $valid = ['code' => $this->uniq('P'), 'name' => 'x', 'face_value' => 100, 'routes' => [['supplier_product_id' => $mobile->id]], 'prices' => []];

        foreach ([
            ['code' => 'bad code'],
            ['code' => $existing->code],
            ['routes' => [['supplier_product_id' => $fifty->id]]],
            ['routes' => [['supplier_product_id' => $mobile->id], ['supplier_product_id' => $mobile->id]]],
            ['routes' => [['supplier_product_id' => 999999999]]],
            ['routes' => [['supplier_product_id' => $mobile->id, 'priority' => -1]]],
            ['prices' => ['ctcc' => '99']],
            ['prices' => ['cmcc' => 'abc']],
        ] as $override) {
            $response = $this->jsonRequest('POST', '/admin/products', $token, array_merge($valid, $override));
            $this->assertSame(422, $response->getStatusCode(), json_encode($override));
        }

        $this->assertSame(422, $this->jsonRequest('PUT', "/admin/products/{$existing->id}", $token, ['code' => 'changed'])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('PUT', "/admin/products/{$existing->id}", $token, ['face_value' => 50])->getStatusCode());
        $this->assertSame('disabled', $this->body($this->jsonRequest('POST', "/admin/products/{$existing->id}/status", $token, ['status' => 'disabled']))['status']);
    }

    public function testWarningsForEmptyAndDisabledRoutes()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['product.view']));
        $offShelf = $this->createSupplierProduct($this->createSupplier(), ['cmcc'], 100, '98.00', 'disabled');
        $stopped = $this->createSupplierProduct($this->createSupplier(['*'], 'disabled'), ['cmcc']);
        $empty = $this->createProduct([]);
        $withDisabled = $this->createProduct([$offShelf->id => 1, $stopped->id => 2]);

        $warnings = fn ($product) => $this->body($this->jsonRequest('GET', '/admin/products?keyword=' . $product->code, $token))['data'][0]['warnings'];
        $this->assertCount(1, $warnings($empty));
        $this->assertCount(2, $warnings($withDisabled));
    }

    public function testListWithNoMatches()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['product.view']));
        $response = $this->jsonRequest('GET', '/admin/products?keyword=' . $this->uniq('none_'), $token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->body($response)['data']);
    }
}
