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
use App\Model\Product;
use App\Model\ProductPrice;
use App\Model\ProductRoute;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\Model\SupplierProductOperator;
use App\Model\SupplierProvince;

/**
 * 直接在库里建供应商、供应商商品、平台商品、号段，tearDown 时调用 cleanUpCatalog() 清理。
 * 通过接口建出来的记录用 trackXxx() 登记，一样会被清理。
 */
trait CreatesCatalog
{
    private array $supplierIds = [];

    private array $supplierProductIds = [];

    private array $productIds = [];

    private array $segmentIds = [];

    private function cleanUpCatalog(): void
    {
        ProductRoute::whereIn('product_id', $this->productIds)->delete();
        ProductRoute::whereIn('supplier_product_id', $this->supplierProductIds)->delete();
        ProductPrice::whereIn('product_id', $this->productIds)->delete();
        Product::destroy($this->productIds);
        SupplierProductOperator::whereIn('supplier_product_id', $this->supplierProductIds)->delete();
        SupplierProduct::destroy($this->supplierProductIds);
        SupplierProvince::whereIn('supplier_id', $this->supplierIds)->delete();
        Supplier::destroy($this->supplierIds);
        MobileSegment::destroy($this->segmentIds);
        $this->supplierIds = $this->supplierProductIds = $this->productIds = $this->segmentIds = [];
    }

    private function uniq(string $prefix): string
    {
        return $prefix . substr(str_replace('.', '', uniqid('', true)), -12);
    }

    /**
     * @param list<string> $provinces
     */
    private function createSupplier(array $provinces = ['*'], string $status = 'active'): Supplier
    {
        $supplier = Supplier::create([
            'name' => $this->uniq('供应商'),
            'code' => $this->uniq('s_'),
            'driver' => 'mock',
            'config' => null,
            'status' => $status,
        ]);
        $this->supplierIds[] = $supplier->id;
        foreach ($provinces as $province) {
            SupplierProvince::create(['supplier_id' => $supplier->id, 'province' => $province]);
        }

        return $supplier;
    }

    /**
     * @param list<string> $operators
     */
    private function createSupplierProduct(Supplier $supplier, array $operators, int $faceValue = 100, string $cost = '98.00', string $status = 'active'): SupplierProduct
    {
        $product = SupplierProduct::create([
            'supplier_id' => $supplier->id,
            'name' => $this->uniq('sp'),
            'face_value' => $faceValue,
            'cost_price' => $cost,
            'external_code' => 'ext',
            'status' => $status,
        ]);
        $this->supplierProductIds[] = $product->id;
        foreach ($operators as $operator) {
            SupplierProductOperator::create(['supplier_product_id' => $product->id, 'operator' => $operator]);
        }

        return $product;
    }

    /**
     * @param array<int, int> $routes supplier_product_id => priority
     */
    private function createProduct(array $routes, int $faceValue = 100, string $status = 'active'): Product
    {
        $product = Product::create(['code' => $this->uniq('P'), 'name' => '新话费' . $faceValue, 'face_value' => $faceValue, 'status' => $status]);
        $this->productIds[] = $product->id;
        foreach ($routes as $supplierProductId => $priority) {
            ProductRoute::create(['product_id' => $product->id, 'supplier_product_id' => $supplierProductId, 'priority' => $priority]);
        }

        return $product;
    }

    /**
     * 号段用 100 开头（真实号段里没有），避免和导入的号段库撞上。
     *
     * @return string 这个号段下的一个手机号
     */
    private function createSegment(string $operator, string $province, bool $virtual = false): string
    {
        do {
            $segment = '100' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (MobileSegment::where('segment', $segment)->exists());
        $row = MobileSegment::create(['segment' => $segment, 'operator' => $operator, 'province' => $province, 'city' => null, 'is_virtual' => $virtual]);
        $this->segmentIds[] = $row->id;

        return $segment . '1234';
    }
}
