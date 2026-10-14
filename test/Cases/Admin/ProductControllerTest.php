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

use App\Model\MerchantProduct;
use App\Model\Order;
use App\Model\Product;
use App\Model\ProductDailyStat;
use App\Model\ProductPrice;
use App\Model\ProductRoute;
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
        $category = $this->createCategory();

        $created = $this->jsonRequest('POST', '/admin/products', $token, [
            'category_id' => $category->id,
            'code' => 'IGNORED',
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
        $code = $body['code'];
        $this->assertMatchesRegularExpression('/^HF100-\d{3,}$/', $code, '编码按面值自动生成，传了也不用');
        $this->assertSame($category->id, $body['category_id']);
        $this->assertSame($category->name, $body['category_name']);
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
        $this->assertSame(['*'], array_column($options, 'provinces', 'id')[$telecom->id], '绑定选项带覆盖省份，前端展示用');
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
        $valid = ['category_id' => $this->createCategory()->id, 'name' => 'x', 'face_value' => 100, 'routes' => [['supplier_product_id' => $mobile->id]], 'prices' => []];

        foreach ([
            ['category_id' => null],
            ['category_id' => 999999999],
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
        $this->assertSame(422, $this->jsonRequest('PUT', "/admin/products/{$existing->id}", $token, ['category_id' => 999999999])->getStatusCode());
        $this->assertSame('disabled', $this->body($this->jsonRequest('POST', "/admin/products/{$existing->id}/status", $token, ['status' => 'disabled']))['status']);
    }

    public function testCodeIsGeneratedPerFaceValue()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['product.manage']));
        $create = function (int $faceValue) use ($token) {
            $body = $this->body($this->jsonRequest('POST', '/admin/products', $token, [
                'category_id' => $this->createCategory()->id, 'name' => 'x', 'face_value' => $faceValue, 'routes' => [], 'prices' => [],
            ]));
            $this->productIds[] = $body['id'];

            return $body['code'];
        };

        // 没用过的面值从 001 开始；同面值按已有最大序号递增
        $this->assertSame('HF98765-001', $create(98765));
        $this->assertSame('HF98765-002', $create(98765));
        $this->assertSame('HF98764-001', $create(98764));
        $first = $create(50);
        $second = $create(50);
        $this->assertSame((int) substr($first, 5) + 1, (int) substr($second, 5));
    }

    public function testDeleteProductRulesAndHistory()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['product.view', 'product.manage', 'merchant.view']));
        $sp = $this->createSupplierProduct($this->createSupplier(), ['cmcc'], 98761);
        $product = $this->createProduct([$sp->id => 1], 98761);
        ProductPrice::create(['product_id' => $product->id, 'operator' => 'cmcc', 'default_price' => '98760.00']);
        $merchant = $this->createMerchant();
        $opened = $this->openProduct($merchant, $product, ['cmcc' => '98760.50']);

        $blocked = $this->jsonRequest('DELETE', "/admin/products/{$product->id}", $token);
        $this->assertSame(422, $blocked->getStatusCode(), '还有商户开通着');
        $this->assertStringContainsString('1 个商户', (string) $blocked->getBody());
        $opened->update(['status' => 'disabled']);

        $order = Order::create([
            'order_no' => date('YmdHis') . random_int(100000, 999999), 'merchant_id' => $merchant->id, 'merchant_order_no' => $this->uniq('M'),
            'product_id' => $product->id, 'product_code' => $product->code, 'product_name' => $product->name, 'mobile' => '10090000001',
            'operator' => 'cmcc', 'province' => '广东', 'face_value' => 98761, 'sale_price' => '98760.50', 'status' => Order::STATUS_PENDING, 'notify_status' => 'none',
        ]);
        $this->assertSame(422, $this->jsonRequest('DELETE', "/admin/products/{$product->id}", $token)->getStatusCode(), '还有没出结果的订单');
        Order::where('id', $order->id)->update(['status' => Order::STATUS_FAILED, 'finished_at' => date('Y-m-d H:i:s')]);
        ProductDailyStat::create([
            'stat_date' => date('Y-m-d', strtotime('-1 day')), 'product_id' => $product->id, 'operator' => 'cmcc', 'order_count' => 1, 'success_count' => 0,
            'failed_count' => 1, 'unfinished_count' => 0, 'total_duration' => 0, 'computed_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $deleted = $this->jsonRequest('DELETE', "/admin/products/{$product->id}", $token);
            $this->assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
            $this->assertNull(Product::find($product->id));
            $this->assertNotNull(Product::withTrashed()->find($product->id), '软删除');
            $this->assertSame(0, MerchantProduct::where('product_id', $product->id)->count(), '已关闭的开通记录一起清掉');
            $this->assertSame(0, ProductRoute::where('product_id', $product->id)->count(), '解除绑定，供应商商品可以删了');
            $this->assertSame(0, ProductPrice::where('product_id', $product->id)->count());
            $this->assertSame(404, $this->jsonRequest('DELETE', "/admin/products/{$product->id}", $token)->getStatusCode());

            $this->assertSame(0, $this->body($this->jsonRequest('GET', '/admin/products?keyword=' . $product->code, $token))['total']);
            $this->assertNotContains($product->id, array_column($this->body($this->jsonRequest('GET', "/admin/merchants/{$merchant->id}/available-products", $token)), 'id'));

            // 历史统计照常显示名字
            $stats = $this->body($this->jsonRequest('GET', '/admin/product-stats?product_id=' . $product->id . '&date_from=' . date('Y-m-d', strtotime('-1 day')), $this->loginAs($this->createAdminWithPermissions(['product.view']))));
            $this->assertSame($product->name, $stats['data'][0]['product_name']);

            // 编码不复用：同面值新建的商品排在已删除的后面
            $created = $this->body($this->jsonRequest('POST', '/admin/products', $token, [
                'category_id' => $this->createCategory()->id, 'name' => 'x', 'face_value' => 98761, 'routes' => [], 'prices' => [],
            ]));
            $this->productIds[] = $created['id'];
            $this->assertNotSame($product->code, $created['code']);
        } finally {
            Order::where('id', $order->id)->delete();
            ProductDailyStat::where('product_id', $product->id)->delete();
        }
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
