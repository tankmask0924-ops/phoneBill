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
use App\Dao\ProductDailyStatDao;
use App\Dao\ProductDao;
use App\Dao\ProductPriceDao;
use App\Dao\ProductRouteDao;
use App\Enum\Operator;
use App\Model\AdminUser;
use App\Model\Product;
use App\Model\ProductRoute;
use App\Service\AbstractService;
use App\Service\Merchant\MerchantPriceService;
use App\Service\Product\ProductRouteService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商品中心 - 平台商品：按面值建，一个商品覆盖多个运营商（规则见 docs/project.md 2.1、2.2）。
 *
 * - 绑定多个同面值的供应商商品，各带优先级；覆盖哪些运营商由绑定的供应商商品决定；
 * - 默认售价按运营商设置，只能给已覆盖的运营商设，只用来在给商户开通商品时带出初始价格；
 * - 商品编码是商户下单时传的，面值决定能绑哪些供应商商品，这两项建好后都不能改；
 * - 不提供删除，不卖了就下架。
 */
class ProductAdminService extends AbstractService
{
    use ValidatesAdminInput;

    private const MODULE = 'product';

    private const MAX_PER_PAGE = 100;

    private const DEFAULT_PRIORITY = 10;

    #[Inject]
    protected ProductDailyStatDao $statDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected ProductPriceDao $productPriceDao;

    #[Inject]
    protected ProductRouteDao $productRouteDao;

    #[Inject]
    protected SupplierProductAdminService $supplierProductAdminService;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    #[Inject]
    protected ProductRouteService $routeService;

    #[Inject]
    protected MerchantPriceService $priceService;

    /**
     * @param array<string, mixed> $query keyword / face_value / status
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 15)));

        $builder = $this->productDao->newQuery();
        $keyword = trim((string) ($query['keyword'] ?? ''));
        if ($keyword !== '') {
            $builder->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%"));
        }
        if (isset($query['face_value']) && is_numeric($query['face_value'])) {
            $builder->where('face_value', (int) $query['face_value']);
        }
        if (in_array($query['status'] ?? null, ['active', 'disabled'], true)) {
            $builder->where('status', $query['status']);
        }

        $total = (clone $builder)->count();
        $products = $builder->orderBy('face_value')->orderBy('id')->forPage($page, $perPage)->get();

        return [
            'data' => $this->formatMany($products->all()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function supplierProductOptions(mixed $faceValue): array
    {
        return $this->supplierProductAdminService->optionsForFaceValue($this->faceValue($faceValue));
    }

    /**
     * @param array<string, mixed> $data code / name / face_value / remark / routes / prices
     * @return array<string, mixed>
     */
    public function create(AdminUser $operator, array $data, ?string $ip): array
    {
        $code = (string) ($data['code'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $code) !== 1) {
            throw new HttpException(422, '商品编码为 1~32 位字母、数字、_ 或 -');
        }
        if ($this->productDao->newQuery()->where('code', $code)->exists()) {
            throw new HttpException(422, "商品编码「{$code}」已存在");
        }
        $faceValue = $this->faceValue($data['face_value'] ?? null);
        $name = $this->requiredString($data['name'] ?? null, '商品名称', 64);
        [$routes, $covered] = $this->validateRoutes($data['routes'] ?? [], $faceValue);
        $prices = $this->validatePrices($data['prices'] ?? [], $covered);

        $result = Db::transaction(function () use ($operator, $code, $name, $faceValue, $routes, $prices, $data, $ip) {
            $product = $this->productDao->create([
                'code' => $code,
                'name' => $name,
                'face_value' => $faceValue,
                'status' => 'active',
                'remark' => $this->remark($data['remark'] ?? null),
            ]);
            $this->productRouteDao->replaceForProduct($product->id, $routes);
            $this->productPriceDao->replaceForProduct($product->id, $prices);
            $after = $this->formatMany([$product])[0];
            $this->operationLogDao->record($operator->id, self::MODULE, 'create_product', 'product', $product->id, null, $after, $ip);

            return $after;
        });
        // 有商户可能在建好之前就用这个编码下过单，缓存了「不存在」
        $this->priceService->flush();
        $this->routeService->flushConfig();

        return $result;
    }

    /**
     * 改了绑定没改默认售价时，已经不覆盖的运营商的默认售价会被去掉。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(AdminUser $operator, int $id, array $data, ?string $ip): array
    {
        $product = $this->findOrFail($id);
        if (array_key_exists('code', $data) && $data['code'] !== $product->code) {
            throw new HttpException(422, '商品编码建好后不能修改，商户下单要用');
        }
        if (array_key_exists('face_value', $data) && (int) $data['face_value'] !== $product->face_value) {
            throw new HttpException(422, '面值建好后不能修改，需要的话新建一个商品');
        }

        $result = Db::transaction(function () use ($operator, $product, $data, $ip) {
            $before = $this->formatMany([$product])[0];
            $attrs = [];
            if (array_key_exists('name', $data)) {
                $attrs['name'] = $this->requiredString($data['name'], '商品名称', 64);
            }
            if (array_key_exists('remark', $data)) {
                $attrs['remark'] = $this->remark($data['remark']);
            }

            if (array_key_exists('routes', $data)) {
                [$routes, $covered] = $this->validateRoutes($data['routes'], $product->face_value);
                $this->productRouteDao->replaceForProduct($product->id, $routes);
            } else {
                $covered = $before['operators'];
            }
            $prices = array_key_exists('prices', $data)
                ? $this->validatePrices($data['prices'], $covered)
                : array_intersect_key($before['prices'], array_flip($covered));
            $this->productPriceDao->replaceForProduct($product->id, $prices);

            if ($attrs !== []) {
                $this->productDao->update($product->id, $attrs);
            }

            $after = $this->formatMany([$this->findOrFail($product->id)])[0];
            $this->operationLogDao->record($operator->id, self::MODULE, 'update_product', 'product', $product->id, $before, $after, $ip);

            return $after;
        });
        $this->priceService->flush();
        $this->routeService->flushConfig();

        return $result;
    }

    /**
     * 上架 / 下架。
     *
     * @return array<string, mixed>
     */
    public function changeStatus(AdminUser $operator, int $id, mixed $status, ?string $ip): array
    {
        $status = $this->status($status);
        $product = $this->findOrFail($id);
        if ($status !== $product->status) {
            $this->productDao->update($product->id, ['status' => $status]);
            $this->operationLogDao->record(
                $operator->id,
                self::MODULE,
                $status === 'active' ? 'enable_product' : 'disable_product',
                'product',
                $product->id,
                ['status' => $product->status],
                ['status' => $status],
                $ip
            );
            $this->priceService->flush();
            $product = $this->findOrFail($product->id);
        }

        return $this->formatMany([$product])[0];
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

        return array_column($this->formatMany($this->productDao->newQuery()->whereIn('id', $ids)->get()->all()), null, 'id');
    }

    private function findOrFail(int $id): Product
    {
        $product = $this->productDao->find($id);
        if (! $product) {
            throw new HttpException(404, '平台商品不存在');
        }

        return $product;
    }

    /**
     * @return array{0: list<array{supplier_product_id: int, priority: int}>, 1: list<string>} 绑定、覆盖的运营商
     */
    private function validateRoutes(mixed $routes, int $faceValue): array
    {
        if (! is_array($routes)) {
            throw new HttpException(422, 'routes 必须是数组');
        }
        $result = [];
        foreach ($routes as $route) {
            $id = is_array($route) && is_numeric($route['supplier_product_id'] ?? null) ? (int) $route['supplier_product_id'] : 0;
            $priority = $route['priority'] ?? self::DEFAULT_PRIORITY;
            if (! is_numeric($priority) || (int) $priority != $priority || $priority < 0 || $priority > 9999) {
                throw new HttpException(422, '优先级必须是 0~9999 的整数');
            }
            if (isset($result[$id])) {
                throw new HttpException(422, '同一个供应商商品不能重复绑定');
            }
            $result[$id] = ['supplier_product_id' => $id, 'priority' => (int) $priority];
        }

        $described = $this->supplierProductAdminService->describe(array_keys($result));
        $operators = [];
        foreach (array_keys($result) as $id) {
            if (! isset($described[$id])) {
                throw new HttpException(422, "供应商商品 {$id} 不存在");
            }
            if ($described[$id]['face_value'] !== $faceValue) {
                throw new HttpException(422, "「{$described[$id]['name']}」的面值是 {$described[$id]['face_value']} 元，和商品面值不一致");
            }
            $operators = array_merge($operators, $described[$id]['operators']);
        }

        return [array_values($result), array_values(array_intersect(Operator::values(), $operators))];
    }

    /**
     * 空值表示不设；只能给已覆盖的运营商设。
     *
     * @param list<string> $covered
     * @return array<string, string>
     */
    private function validatePrices(mixed $prices, array $covered): array
    {
        if (! is_array($prices)) {
            throw new HttpException(422, 'prices 必须是 {运营商: 默认售价}');
        }
        $result = [];
        foreach (Operator::cases() as $op) {
            $value = $prices[$op->value] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (! in_array($op->value, $covered, true)) {
                throw new HttpException(422, "还没有绑定支持{$op->label()}的供应商商品，不能设置{$op->label()}的默认售价");
            }
            $result[$op->value] = $this->money($value, "{$op->label()}默认售价");
        }

        return $result;
    }

    /**
     * @param list<Product> $products
     * @return list<array<string, mixed>>
     */
    private function formatMany(array $products): array
    {
        $ids = array_map(static fn (Product $p) => $p->id, $products);
        $routesByProduct = $this->productRouteDao->routesFor($ids);
        $pricesByProduct = $this->productPriceDao->pricesFor($ids);
        $supplierProductIds = [];
        foreach ($routesByProduct as $routes) {
            foreach ($routes as $route) {
                $supplierProductIds[] = $route->supplier_product_id;
            }
        }
        $described = $this->supplierProductAdminService->describe(array_values(array_unique($supplierProductIds)));
        $yesterday = $this->statDao->dayTotals($ids, date('Y-m-d', strtotime('-1 day')));

        return array_map(function (Product $p) use ($routesByProduct, $pricesByProduct, $described, $yesterday) {
            $routes = array_map(static fn (ProductRoute $r) => [
                'supplier_product_id' => $r->supplier_product_id,
                'priority' => $r->priority,
                'supplier_product' => $described[$r->supplier_product_id] ?? null,
            ], $routesByProduct[$p->id] ?? []);
            $operators = [];
            foreach ($routes as $route) {
                $operators = array_merge($operators, $route['supplier_product']['operators'] ?? []);
            }
            $prices = $pricesByProduct[$p->id] ?? [];

            return [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'face_value' => $p->face_value,
                'status' => $p->status,
                'remark' => $p->remark,
                'operators' => array_values(array_intersect(Operator::values(), $operators)),
                'prices' => $prices,
                'routes' => $routes,
                'warnings' => $this->warnings($routes, $prices),
                // 昨天各运营商合起来的成功率、平均耗时，没有订单时为 null，明细看商品统计
                'yesterday_stats' => isset($yesterday[$p->id])
                    ? ProductStatsAdminService::summarize($yesterday[$p->id]['order_count'], $yesterday[$p->id]['success_count'], $yesterday[$p->id]['failed_count'], $yesterday[$p->id]['total_duration'])
                    : null,
                'created_at' => $p->created_at?->toDateTimeString(),
                'updated_at' => $p->updated_at?->toDateTimeString(),
            ];
        }, $products);
    }

    /**
     * 配置上的提醒，不拦截保存。
     *
     * @param list<array<string, mixed>> $routes
     * @param array<string, string> $prices
     * @return list<string>
     */
    private function warnings(array $routes, array $prices): array
    {
        if ($routes === []) {
            return ['还没有绑定供应商商品，商户下单会被拒绝'];
        }
        $warnings = [];
        foreach ($routes as $route) {
            $sp = $route['supplier_product'];
            if ($sp === null) {
                continue;
            }
            $label = "「{$sp['supplier_name']} · {$sp['name']}」";
            if ($sp['supplier_status'] !== 'active') {
                $warnings[] = "{$label}的供应商已停用，不会参与选路";
            } elseif ($sp['status'] !== 'active') {
                $warnings[] = "{$label}已下架，不会参与选路";
            }
            foreach ($sp['operators'] as $operator) {
                $price = $prices[$operator] ?? null;
                if ($price !== null && bccomp($price, $sp['cost_price'], 2) < 0) {
                    $opLabel = Operator::from($operator)->label();
                    $warnings[] = "{$opLabel}默认售价 {$price} 低于{$label}的成本价 {$sp['cost_price']}";
                }
            }
        }

        return $warnings;
    }
}
