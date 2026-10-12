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
 * 商品分类：增删改、名称唯一、在用的不能删、平台商品按分类筛选。
 *
 * @internal
 * @coversNothing
 */
class ProductCategoryControllerTest extends HttpTestCase
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

    public function testCrudAndDeleteGuard()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['product.view', 'product.manage']));
        $name = $this->uniq('分类');

        $created = $this->jsonRequest('POST', '/admin/product-categories', $token, ['name' => $name, 'sort' => 5]);
        $this->assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        $category = $this->body($created);
        $this->categoryIds[] = $category['id'];
        $this->assertSame(5, $category['sort']);
        $this->assertSame(0, $category['product_count']);

        // 名称唯一、排序范围
        $this->assertSame(422, $this->jsonRequest('POST', '/admin/product-categories', $token, ['name' => $name])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('POST', '/admin/product-categories', $token, ['name' => ''])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('POST', '/admin/product-categories', $token, ['name' => $this->uniq('分类'), 'sort' => -1])->getStatusCode());

        $renamed = $this->uniq('分类');
        $updated = $this->body($this->jsonRequest('PUT', "/admin/product-categories/{$category['id']}", $token, ['name' => $renamed]));
        $this->assertSame($renamed, $updated['name']);

        // 有平台商品在用：不能删，列表里带商品数；平台商品可以按分类筛
        $product = $this->createProduct([]);
        $product->update(['category_id' => $category['id']]);
        $list = $this->body($this->jsonRequest('GET', '/admin/product-categories', $token));
        $row = array_values(array_filter($list, static fn (array $c) => $c['id'] === $category['id']))[0];
        $this->assertSame(1, $row['product_count']);
        $products = $this->body($this->jsonRequest('GET', "/admin/products?category_id={$category['id']}", $token));
        $this->assertSame([$product->id], array_column($products['data'], 'id'));
        $this->assertSame($renamed, $products['data'][0]['category_name']);
        $this->assertSame(422, $this->jsonRequest('DELETE', "/admin/product-categories/{$category['id']}", $token)->getStatusCode());

        $product->update(['category_id' => null]);
        $this->assertSame(200, $this->jsonRequest('DELETE', "/admin/product-categories/{$category['id']}", $token)->getStatusCode());
        $this->assertSame(404, $this->jsonRequest('DELETE', "/admin/product-categories/{$category['id']}", $token)->getStatusCode());
    }

    public function testViewOnlyCannotManage()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['product.view']));

        $this->assertSame(200, $this->jsonRequest('GET', '/admin/product-categories', $token)->getStatusCode());
        $this->assertSame(403, $this->jsonRequest('POST', '/admin/product-categories', $token, ['name' => $this->uniq('分类')])->getStatusCode());
    }
}
