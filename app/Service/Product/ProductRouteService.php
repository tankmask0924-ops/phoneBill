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

namespace App\Service\Product;

use App\Dao\ProductRouteDao;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 选路：一个号码（运营商 + 省份）在平台商品下可以走哪些供应商商品，规则见 docs/project.md 2.1。
 * 后台号段查询页和下单都用这里，保证两边看到的结果一致。
 */
class ProductRouteService extends AbstractService
{
    #[Inject]
    protected ProductRouteDao $productRouteDao;

    /**
     * @param list<int> $productIds
     * @return array<int, list<array<string, mixed>>> product_id => 候选列表，已按尝试顺序排好
     */
    public function candidates(array $productIds, string $operator, string $province): array
    {
        if ($productIds === []) {
            return [];
        }
        $result = array_fill_keys($productIds, []);
        foreach ($this->productRouteDao->candidates($productIds, $operator, $province) as $row) {
            $result[(int) $row->product_id][] = [
                'supplier_product_id' => (int) $row->supplier_product_id,
                'supplier_product_name' => $row->supplier_product_name,
                'external_code' => $row->external_code,
                'cost_price' => $row->cost_price,
                'priority' => (int) $row->priority,
                'supplier_id' => (int) $row->supplier_id,
                'supplier_name' => $row->supplier_name,
                'supplier_code' => $row->supplier_code,
                'supplier_driver' => $row->supplier_driver,
            ];
        }

        return $result;
    }
}
