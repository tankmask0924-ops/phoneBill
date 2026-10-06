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

namespace App\Dao;

use App\Model\ProductRoute;
use Hyperf\Collection\Collection;
use Hyperf\Database\Query\Builder;
use Hyperf\DbConnection\Db;

class ProductRouteDao extends AbstractDao
{
    protected string $model = ProductRoute::class;

    /**
     * @param list<int> $productIds
     * @return array<int, list<ProductRoute>> product_id => 绑定（按优先级排好）
     */
    public function routesFor(array $productIds): array
    {
        $result = [];
        $rows = $this->newQuery()->whereIn('product_id', $productIds)->orderBy('priority')->orderBy('id')->get();
        foreach ($rows as $row) {
            $result[$row->product_id][] = $row;
        }

        return $result;
    }

    /**
     * @param list<array{supplier_product_id: int, priority: int}> $routes
     */
    public function replaceForProduct(int $productId, array $routes): void
    {
        $this->newQuery()->where('product_id', $productId)->delete();
        foreach ($routes as $route) {
            $this->create(['product_id' => $productId] + $route);
        }
    }

    public function isSupplierProductBound(int $supplierProductId): bool
    {
        return $this->newQuery()->where('supplier_product_id', $supplierProductId)->exists();
    }

    /**
     * 某个号码（运营商 + 省份）在这些平台商品下能走的供应商商品，规则见 docs/project.md 2.1：
     * 支持该运营商、供应商覆盖该省份、供应商和供应商商品都启用。
     * 按商品分组，组内按优先级、成本、绑定先后排序。
     *
     * @param list<int> $productIds
     * @return Collection<int, object> 每行：product_id、priority、supplier_product_id、supplier_product_name、
     *                                 external_code、cost_price、supplier_id、supplier_name、supplier_code、supplier_driver
     */
    public function candidates(array $productIds, string $operator, string $province): Collection
    {
        return Db::table('product_routes as pr')
            ->join('supplier_products as sp', 'sp.id', '=', 'pr.supplier_product_id')
            ->join('suppliers as s', 's.id', '=', 'sp.supplier_id')
            ->whereIn('pr.product_id', $productIds)
            ->where('sp.status', 'active')
            ->where('s.status', 'active')
            ->whereExists(fn (Builder $q) => $q->select(Db::raw(1))
                ->from('supplier_product_operators as o')
                ->whereColumn('o.supplier_product_id', 'sp.id')
                ->where('o.operator', $operator))
            ->whereExists(fn (Builder $q) => $q->select(Db::raw(1))
                ->from('supplier_provinces as p')
                ->whereColumn('p.supplier_id', 's.id')
                ->whereIn('p.province', [$province, '*']))
            ->orderBy('pr.product_id')
            ->orderBy('pr.priority')
            ->orderBy('sp.cost_price')
            ->orderBy('pr.id')
            ->get([
                'pr.product_id',
                'pr.priority',
                'sp.id as supplier_product_id',
                'sp.name as supplier_product_name',
                'sp.external_code',
                'sp.cost_price',
                's.id as supplier_id',
                's.name as supplier_name',
                's.code as supplier_code',
                's.driver as supplier_driver',
            ]);
    }
}
