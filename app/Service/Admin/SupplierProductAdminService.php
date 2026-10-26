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

namespace App\Service\Admin;

use App\Dao\AdminOperationLogDao;
use App\Dao\OrderAttemptDao;
use App\Dao\ProductDao;
use App\Dao\ProductRouteDao;
use App\Dao\SupplierDao;
use App\Dao\SupplierProductDao;
use App\Dao\SupplierProductOperatorDao;
use App\Dao\SupplierProductProvinceDao;
use App\Enum\Operator;
use App\Enum\Province;
use App\Model\AdminUser;
use App\Model\ProductRoute;
use App\Model\SupplierProduct;
use App\Service\AbstractService;
use App\Service\Merchant\MerchantPriceService;
use App\Service\Product\ProductRouteService;
use App\Service\Supplier\SupplierGateway;
use App\Supplier\UpstreamProduct;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Throwable;

/**
 * 商品中心 - 供应商商品：面值、成本价、支持的运营商、覆盖的省份、供应商侧商品编码、上下架。
 *
 * - 覆盖省份必填（可选「全国」），号码所在省份在里面才会选到这个商品；
 * - 所属供应商建好后不能改；
 * - 已经被平台商品绑定的，面值不能改（平台商品只能绑同面值的供应商商品）；
 * - 删除是软删除：被平台商品绑定着、或者有提交了还没出结果的单时不能删；删除后历史订单照常显示名字；
 * - 一键解绑：从绑定了它的全部平台商品里去掉（改的是平台商品的配置，要 product.manage），已提交的单不受影响，
 *   之后换供应商、手动补单时不再用它。
 */
class SupplierProductAdminService extends AbstractService
{
    use ValidatesAdminInput;

    private const MODULE = 'product';

    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected SupplierProductDao $supplierProductDao;

    #[Inject]
    protected SupplierProductOperatorDao $operatorDao;

    #[Inject]
    protected SupplierProductProvinceDao $provinceDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected ProductRouteDao $productRouteDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected MerchantPriceService $priceService;

    #[Inject]
    protected OrderAttemptDao $attemptDao;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    #[Inject]
    protected ProductRouteService $routeService;

    #[Inject]
    protected SupplierGateway $supplierGateway;

    /**
     * @param array<string, mixed> $query keyword / supplier_id / operator / province / face_value / status
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 15)));

        $builder = $this->supplierProductDao->newQuery();
        $keyword = trim((string) ($query['keyword'] ?? ''));
        if ($keyword !== '') {
            $builder->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")->orWhere('external_code', 'like', "%{$keyword}%"));
        }
        foreach (['supplier_id', 'face_value'] as $field) {
            if (isset($query[$field]) && is_numeric($query[$field])) {
                $builder->where($field, (int) $query[$field]);
            }
        }
        if (in_array($query['status'] ?? null, ['active', 'disabled'], true)) {
            $builder->where('status', $query['status']);
        }
        if (is_string($query['operator'] ?? null) && Operator::tryFrom($query['operator']) !== null) {
            $builder->whereIn('id', fn ($q) => $q->select('supplier_product_id')->from('supplier_product_operators')->where('operator', $query['operator']));
        }
        // 按省份筛：覆盖了这个省份的（含「全国」）
        if (is_string($query['province'] ?? null) && Province::isValid($query['province'])) {
            $builder->whereIn('id', fn ($q) => $q->select('supplier_product_id')->from('supplier_product_provinces')->whereIn('province', [$query['province'], Province::ALL]));
        }

        $total = (clone $builder)->count();
        $products = $builder->orderBy('supplier_id')->orderBy('face_value')->orderBy('id')->forPage($page, $perPage)->get();

        return [
            'data' => $this->formatMany($products->all()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 实时查供应商那边的可售商品，给建供应商商品时挑选；added 表示这个编码在本供应商下已经建过。
     *
     * @return list<array<string, mixed>>
     */
    public function upstreamProducts(mixed $supplierId): array
    {
        $supplier = is_numeric($supplierId) ? $this->supplierDao->find((int) $supplierId) : null;
        if ($supplier === null) {
            throw new HttpException(404, '供应商不存在');
        }
        if (! $this->supplierGateway->supportsUpstreamProducts($supplier)) {
            throw new HttpException(422, '该供应商的接口不支持查询商品，请手动填写供应商商品编码');
        }
        try {
            $products = $this->supplierGateway->upstreamProducts($supplier);
        } catch (Throwable $e) {
            throw new HttpException(422, mb_substr('查询上游商品失败：' . $e->getMessage(), 0, 255));
        }
        $codes = array_map(static fn (UpstreamProduct $p) => $p->code, $products);
        $added = array_flip($codes === [] ? [] : $this->supplierProductDao->existingExternalCodes($supplier->id, $codes));

        return array_map(static fn (UpstreamProduct $p) => $p->toArray() + ['added' => isset($added[$p->code])], $products);
    }

    /**
     * @param array<string, mixed> $data supplier_id / name / face_value / cost_price / external_code / operators / provinces / remark
     * @return array<string, mixed>
     */
    public function create(AdminUser $operator, array $data, ?string $ip): array
    {
        $supplierId = is_numeric($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : 0;
        if ($this->supplierDao->find($supplierId) === null) {
            throw new HttpException(422, '请选择有效的供应商');
        }
        $attrs = [
            'supplier_id' => $supplierId,
            'name' => $this->requiredString($data['name'] ?? null, '商品名称', 64),
            'face_value' => $this->faceValue($data['face_value'] ?? null),
            'cost_price' => $this->money($data['cost_price'] ?? null, '成本价'),
            'external_code' => $this->requiredString($data['external_code'] ?? null, '供应商商品编码', 64),
            'status' => 'active',
            'remark' => $this->remark($data['remark'] ?? null),
        ];
        $operators = $this->operators($data['operators'] ?? null);
        $provinces = $this->provinces($data['provinces'] ?? null);

        return Db::transaction(function () use ($operator, $attrs, $operators, $provinces, $ip) {
            $product = $this->supplierProductDao->create($attrs);
            $this->operatorDao->replaceForSupplierProduct($product->id, $operators);
            $this->provinceDao->replaceForSupplierProduct($product->id, $provinces);
            $after = $this->formatMany([$product])[0];
            $this->operationLogDao->record($operator->id, self::MODULE, 'create_supplier_product', 'supplier_product', $product->id, null, $after, $ip);

            return $after;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(AdminUser $operator, int $id, array $data, ?string $ip): array
    {
        $product = $this->findOrFail($id);
        if (array_key_exists('supplier_id', $data) && (int) $data['supplier_id'] !== $product->supplier_id) {
            throw new HttpException(422, '所属供应商不能修改');
        }

        $result = Db::transaction(function () use ($operator, $product, $data, $ip) {
            $before = $this->formatMany([$product])[0];
            $attrs = [];
            if (array_key_exists('name', $data)) {
                $attrs['name'] = $this->requiredString($data['name'], '商品名称', 64);
            }
            if (array_key_exists('face_value', $data)) {
                $faceValue = $this->faceValue($data['face_value']);
                if ($faceValue !== $product->face_value && $this->productRouteDao->isSupplierProductBound($product->id)) {
                    throw new HttpException(422, '已经被平台商品绑定，面值不能修改；需要的话新建一个供应商商品');
                }
                $attrs['face_value'] = $faceValue;
            }
            if (array_key_exists('cost_price', $data)) {
                $attrs['cost_price'] = $this->money($data['cost_price'], '成本价');
            }
            if (array_key_exists('external_code', $data)) {
                $attrs['external_code'] = $this->requiredString($data['external_code'], '供应商商品编码', 64);
            }
            if (array_key_exists('remark', $data)) {
                $attrs['remark'] = $this->remark($data['remark']);
            }
            if (array_key_exists('operators', $data)) {
                $this->operatorDao->replaceForSupplierProduct($product->id, $this->operators($data['operators']));
            }
            if (array_key_exists('provinces', $data)) {
                $this->provinceDao->replaceForSupplierProduct($product->id, $this->provinces($data['provinces']));
            }
            if ($attrs !== []) {
                $this->supplierProductDao->update($product->id, $attrs);
            }

            $after = $this->formatMany([$this->findOrFail($product->id)])[0];
            $this->operationLogDao->record($operator->id, self::MODULE, 'update_supplier_product', 'supplier_product', $product->id, $before, $after, $ip);

            return $after;
        });
        // 运营商、省份、成本（影响排序）可能变了，事务提交后清选路缓存
        $this->routeService->flushConfig();

        return $result;
    }

    /**
     * 上架 / 下架。下架后不再参与选路。
     *
     * @return array<string, mixed>
     */
    public function changeStatus(AdminUser $operator, int $id, mixed $status, ?string $ip): array
    {
        $status = $this->status($status);
        $product = $this->findOrFail($id);
        if ($status !== $product->status) {
            $this->supplierProductDao->update($product->id, ['status' => $status]);
            $this->operationLogDao->record(
                $operator->id,
                self::MODULE,
                $status === 'active' ? 'enable_supplier_product' : 'disable_supplier_product',
                'supplier_product',
                $product->id,
                ['status' => $product->status],
                ['status' => $status],
                $ip
            );
            $this->routeService->flushConfig();
            $product = $this->findOrFail($product->id);
        }

        return $this->formatMany([$product])[0];
    }

    /**
     * 软删除，连同支持的运营商、覆盖的省份一起清掉。
     */
    public function delete(AdminUser $operator, int $id, ?string $ip): void
    {
        $product = $this->findOrFail($id);
        if ($this->productRouteDao->isSupplierProductBound($product->id)) {
            throw new HttpException(422, "「{$product->name}」还绑定在平台商品上，先在平台商品里解绑再删除");
        }
        if ($this->attemptDao->hasProcessingForSupplierProduct($product->id)) {
            throw new HttpException(422, "「{$product->name}」还有充值中的订单，等出结果后再删除");
        }
        $before = $this->formatMany([$product])[0];
        Db::transaction(function () use ($operator, $product, $before, $ip) {
            $this->operatorDao->replaceForSupplierProduct($product->id, []);
            $this->provinceDao->replaceForSupplierProduct($product->id, []);
            $this->supplierProductDao->delete($product->id);
            $this->operationLogDao->record($operator->id, self::MODULE, 'delete_supplier_product', 'supplier_product', $product->id, $before, null, $ip);
        });
        $this->routeService->flushConfig();
    }

    /**
     * 绑定了这个供应商商品的平台商品，带它在每个商品里的优先级、解绑后这个商品还剩几个绑定。
     *
     * @return list<array{product_id: int, code: string, name: string, status: string, priority: int, remaining_routes: int}>
     */
    public function bindings(int $id): array
    {
        $product = $this->findOrFail($id);
        $productIds = $this->productRouteDao->productIdsForSupplierProduct($product->id);
        $routes = $this->productRouteDao->routesFor($productIds);
        $products = $this->productDao->findManyWithTrashed($productIds);
        $result = [];
        foreach ($productIds as $productId) {
            $own = array_values(array_filter($routes[$productId] ?? [], static fn (ProductRoute $r) => $r->supplier_product_id === $product->id));
            $p = $products[$productId] ?? null;
            $result[] = [
                'product_id' => $productId,
                'code' => $p?->code ?? '',
                'name' => $p?->name ?? "#{$productId}",
                'status' => $p?->status ?? '',
                'priority' => $own[0]->priority ?? 0,
                'remaining_routes' => count($routes[$productId] ?? []) - count($own),
            ];
        }

        return $result;
    }

    /**
     * 一键解绑：事务里删掉这个供应商商品的全部绑定，每个受影响的平台商品记一条操作日志（绑定的前后），返回解绑了几个平台商品。
     */
    public function unbindAll(AdminUser $operator, int $id, ?string $ip): int
    {
        $product = $this->findOrFail($id);
        $count = Db::transaction(function () use ($operator, $product, $ip) {
            $productIds = $this->productRouteDao->productIdsForSupplierProduct($product->id);
            $before = $this->productRouteDao->routesFor($productIds);
            $this->productRouteDao->deleteForSupplierProduct($product->id);
            $simplify = static fn (array $routes) => array_map(static fn (ProductRoute $r) => ['supplier_product_id' => $r->supplier_product_id, 'priority' => $r->priority], $routes);
            foreach ($productIds as $productId) {
                $routes = $before[$productId] ?? [];
                $this->operationLogDao->record($operator->id, self::MODULE, 'unbind_supplier_product', 'product', $productId, ['routes' => $simplify($routes)], [
                    'routes' => $simplify(array_values(array_filter($routes, static fn (ProductRoute $r) => $r->supplier_product_id !== $product->id))),
                    'unbound_supplier_product_id' => $product->id,
                ], $ip);
            }

            return count($productIds);
        });
        $this->priceService->flush();
        $this->routeService->flushConfig();

        return $count;
    }

    /**
     * 平台商品绑定时可选的供应商商品：同面值的全部（含下架的，前端标出来）。
     *
     * @return list<array<string, mixed>>
     */
    public function optionsForFaceValue(int $faceValue): array
    {
        $products = $this->supplierProductDao->newQuery()
            ->where('face_value', $faceValue)
            ->orderBy('supplier_id')
            ->orderBy('id')
            ->get();

        return $this->formatMany($products->all());
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> id => 和列表一样的结构
     */
    public function describe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $products = $this->supplierProductDao->newQuery()->whereIn('id', $ids)->get()->all();

        return array_column($this->formatMany($products), null, 'id');
    }

    private function findOrFail(int $id): SupplierProduct
    {
        $product = $this->supplierProductDao->find($id);
        if (! $product) {
            throw new HttpException(404, '供应商商品不存在');
        }

        return $product;
    }

    /**
     * @param list<SupplierProduct> $products
     * @return list<array<string, mixed>>
     */
    private function formatMany(array $products): array
    {
        $ids = array_map(static fn (SupplierProduct $p) => $p->id, $products);
        $operators = $this->operatorDao->operatorsFor($ids);
        $provinces = $this->provinceDao->provincesFor($ids);
        $suppliers = $this->supplierDao->newQuery()
            ->whereIn('id', array_unique(array_map(static fn (SupplierProduct $p) => $p->supplier_id, $products)))
            ->get(['id', 'name', 'status'])
            ->keyBy('id');
        $boundCounts = $this->productRouteDao->newQuery()
            ->whereIn('supplier_product_id', $ids)
            ->selectRaw('supplier_product_id, count(*) as n')
            ->groupBy('supplier_product_id')
            ->pluck('n', 'supplier_product_id');

        return array_map(static fn (SupplierProduct $p) => [
            'id' => $p->id,
            'supplier_id' => $p->supplier_id,
            'supplier_name' => $suppliers->get($p->supplier_id)?->name,
            'supplier_status' => $suppliers->get($p->supplier_id)?->status,
            'name' => $p->name,
            'face_value' => $p->face_value,
            'cost_price' => $p->cost_price,
            'external_code' => $p->external_code,
            'operators' => $operators[$p->id] ?? [],
            // 覆盖的省份，['*'] 表示全国
            'provinces' => $provinces[$p->id] ?? [],
            'status' => $p->status,
            'remark' => $p->remark,
            'bound_product_count' => (int) ($boundCounts[$p->id] ?? 0),
            'created_at' => $p->created_at?->toDateTimeString(),
            'updated_at' => $p->updated_at?->toDateTimeString(),
        ], $products);
    }
}
