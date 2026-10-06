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
use App\Dao\MerchantProductDao;
use App\Dao\MerchantProductPriceDao;
use App\Dao\ProductDao;
use App\Enum\Operator;
use App\Model\AdminUser;
use App\Model\MerchantProduct;
use App\Service\AbstractService;
use App\Service\Merchant\MerchantPriceService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户中心 - 商户商品与价格（规则见 docs/project.md 2.2）。
 *
 * - 商户必须先开通商品才能下单；价格按「商户 + 商品 + 运营商」设置，没设价格的运营商不能下单；
 * - 开通时前端用商品的默认售价带出初始价格；价格低于成本只提醒、不拦截；
 * - 不卖给这个商户了就关闭（status = disabled），不删记录。
 */
class MerchantProductAdminService extends AbstractService
{
    use ValidatesAdminInput;

    private const MODULE = 'merchant';

    #[Inject]
    protected MerchantAdminService $merchantAdminService;

    #[Inject]
    protected MerchantProductDao $merchantProductDao;

    #[Inject]
    protected MerchantProductPriceDao $priceDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected ProductAdminService $productAdminService;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    #[Inject]
    protected MerchantPriceService $priceService;

    /**
     * 商户已开通（含已关闭）的商品。
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $merchantId): array
    {
        $this->merchantAdminService->findOrFail($merchantId);
        $rows = $this->merchantProductDao->newQuery()->where('merchant_id', $merchantId)->orderBy('id')->get()->all();

        return $this->formatMany($rows);
    }

    /**
     * 还没开通的上架商品，带默认售价，开通时用来带出初始价格。
     *
     * @return list<array<string, mixed>>
     */
    public function available(int $merchantId): array
    {
        $this->merchantAdminService->findOrFail($merchantId);
        $opened = $this->merchantProductDao->newQuery()->where('merchant_id', $merchantId)->pluck('product_id')->all();
        $ids = $this->productDao->newQuery()
            ->where('status', 'active')
            ->whereNotIn('id', $opened)
            ->orderBy('face_value')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return array_values(array_map(static fn (array $p) => [
            'id' => $p['id'],
            'code' => $p['code'],
            'name' => $p['name'],
            'face_value' => $p['face_value'],
            'operators' => $p['operators'],
            'prices' => $p['prices'],
        ], $this->productAdminService->describe($ids)));
    }

    /**
     * 开通或修改：没开通过就新建。
     *
     * @param array<string, mixed> $data status（active / disabled，默认 active）/ prices（{运营商: 价格}，空值表示不设）
     * @return array<string, mixed>
     */
    public function save(AdminUser $operator, int $merchantId, int $productId, array $data, ?string $ip): array
    {
        $this->merchantAdminService->findOrFail($merchantId);
        $product = $this->productAdminService->describe([$productId])[$productId] ?? null;
        if ($product === null) {
            throw new HttpException(404, '平台商品不存在');
        }
        $existing = $this->merchantProductDao->findFor($merchantId, $productId);
        $status = array_key_exists('status', $data) ? $this->status($data['status']) : ($existing?->status ?? 'active');
        $prices = $this->validatePrices($data['prices'] ?? [], $product['operators']);

        $result = Db::transaction(function () use ($operator, $merchantId, $productId, $existing, $status, $prices, $ip) {
            $before = $existing ? $this->formatMany([$existing])[0] : null;
            if ($existing) {
                $this->merchantProductDao->update($existing->id, ['status' => $status]);
                $row = $this->merchantProductDao->findFor($merchantId, $productId);
            } else {
                $row = $this->merchantProductDao->create(['merchant_id' => $merchantId, 'product_id' => $productId, 'status' => $status]);
            }
            $this->priceDao->replaceFor($row->id, $prices);
            $after = $this->formatMany([$row])[0];
            $this->operationLogDao->record(
                $operator->id,
                self::MODULE,
                $existing ? 'update_merchant_product' : 'open_merchant_product',
                'merchant',
                $merchantId,
                $before,
                $after,
                $ip
            );

            return $after;
        });
        // 事务提交后再清，避免并发下单把旧价格又写回缓存
        $this->priceService->flush();

        return $result;
    }

    /**
     * 只能给商品覆盖的运营商设价格。
     *
     * @param list<string> $covered
     * @return array<string, string>
     */
    private function validatePrices(mixed $prices, array $covered): array
    {
        if (! is_array($prices)) {
            throw new HttpException(422, 'prices 必须是 {运营商: 价格}');
        }
        $result = [];
        foreach (Operator::cases() as $op) {
            $value = $prices[$op->value] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (! in_array($op->value, $covered, true)) {
                throw new HttpException(422, "这个商品还不支持{$op->label()}，不能设置{$op->label()}的价格");
            }
            $result[$op->value] = $this->money($value, "{$op->label()}价格");
        }

        return $result;
    }

    /**
     * @param list<MerchantProduct> $rows
     * @return list<array<string, mixed>>
     */
    private function formatMany(array $rows): array
    {
        $products = $this->productAdminService->describe(array_map(static fn (MerchantProduct $r) => $r->product_id, $rows));
        $prices = $this->priceDao->pricesFor(array_map(static fn (MerchantProduct $r) => $r->id, $rows));

        return array_map(function (MerchantProduct $row) use ($products, $prices) {
            $product = $products[$row->product_id] ?? null;
            $myPrices = $prices[$row->id] ?? [];

            return [
                'id' => $row->id,
                'merchant_id' => $row->merchant_id,
                'product_id' => $row->product_id,
                'product_code' => $product['code'] ?? null,
                'product_name' => $product['name'] ?? null,
                'product_status' => $product['status'] ?? null,
                'face_value' => $product['face_value'] ?? null,
                'operators' => $product['operators'] ?? [],
                'default_prices' => $product['prices'] ?? [],
                'prices' => $myPrices,
                'status' => $row->status,
                'warnings' => $product === null ? [] : $this->warnings($product, $myPrices),
                'updated_at' => $row->updated_at?->toDateTimeString(),
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $product ProductAdminService::describe() 的一项
     * @param array<string, string> $prices
     * @return list<string>
     */
    private function warnings(array $product, array $prices): array
    {
        $warnings = [];
        if ($product['status'] !== 'active') {
            $warnings[] = '平台商品已下架，商户下单会被拒绝';
        }
        foreach ($product['operators'] as $operator) {
            $label = Operator::from($operator)->label();
            $price = $prices[$operator] ?? null;
            if ($price === null) {
                $warnings[] = "没有设置{$label}的价格，{$label}号码下单会被拒绝";
                continue;
            }
            foreach ($product['routes'] as $route) {
                $sp = $route['supplier_product'];
                if ($sp !== null && in_array($operator, $sp['operators'], true) && bccomp($price, $sp['cost_price'], 2) < 0) {
                    $warnings[] = "{$label}价格 {$price} 低于「{$sp['supplier_name']} · {$sp['name']}」的成本价 {$sp['cost_price']}";
                }
            }
        }

        return $warnings;
    }
}
