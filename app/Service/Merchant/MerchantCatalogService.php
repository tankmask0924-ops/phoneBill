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

namespace App\Service\Merchant;

use App\Dao\MerchantProductDao;
use App\Dao\MerchantProductPriceDao;
use App\Dao\ProductCategoryDao;
use App\Dao\ProductDailyStatDao;
use App\Dao\ProductDao;
use App\Model\Merchant;
use App\Model\Product;
use App\Service\AbstractService;
use App\Service\Admin\ProductStatsAdminService;
use Hyperf\Database\Model\Collection;
use Hyperf\Di\Annotation\Inject;

/**
 * 商户能买的商品和价格（开放接口「查商品」和商户后台「可下单商品」页共用；后者还带近 7 天的成功率、耗时）。
 */
class MerchantCatalogService extends AbstractService
{
    /** 商户后台展示的统计天数（昨天往前，按每天凌晨汇总好的统计表） */
    public const STATS_DAYS = 7;

    #[Inject]
    protected MerchantProductDao $merchantProductDao;

    #[Inject]
    protected MerchantProductPriceDao $merchantPriceDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected ProductDailyStatDao $statDao;

    #[Inject]
    protected ProductCategoryDao $categoryDao;

    /**
     * 已开通、且平台商品还在上架的，按面值排。
     *
     * @return list<array{product_code: string, name: string, face_value: int, prices: array<string, string>}>
     */
    public function openedProducts(Merchant $merchant): array
    {
        $opened = $this->merchantProductDao->newQuery()->where('merchant_id', $merchant->id)->where('status', 'active')->get()->keyBy('product_id');
        $prices = $this->merchantPriceDao->pricesFor($opened->pluck('id')->all());
        $products = $this->productModels($opened->keys()->all());

        $result = [];
        foreach ($products as $product) {
            $result[] = [
                'product_code' => $product->code,
                'name' => $product->name,
                'face_value' => $product->face_value,
                'prices' => $prices[$opened->get($product->id)->id] ?? [],
            ];
        }

        return $result;
    }

    /**
     * 已开通、且平台商品还在上架的平台商品（Model），按面值排。
     *
     * @return Collection<int, Product>
     */
    public function openedProductModels(Merchant $merchant): Collection
    {
        $productIds = $this->merchantProductDao->newQuery()->where('merchant_id', $merchant->id)->where('status', 'active')->pluck('product_id')->all();

        return $this->productModels($productIds);
    }

    /**
     * 商户后台「可下单商品」：每个商品按运营商列出价格，以及平台整体近 7 天（不含今天）的成功率、平均耗时。
     * 没有订单的运营商统计为 null。带上商品分类（老商品可能没有分类，为 null），前端按分类分 tab。
     *
     * @return array{from: string, to: string, products: list<array<string, mixed>>, categories: list<array{id: int, name: string}>}
     */
    public function openedProductsWithStats(Merchant $merchant): array
    {
        $products = $this->openedProducts($merchant);
        $models = $this->productDao->newQuery()->whereIn('code', array_column($products, 'product_code'))->get(['id', 'code', 'category_id'])->keyBy('code');
        $ids = $models->map(static fn (Product $p) => $p->id)->all();
        $categories = $this->categoryDao->optionsFor($models->pluck('category_id')->filter()->unique()->values()->all());
        $categoryNames = array_column($categories, 'name', 'id');
        $from = date('Y-m-d', strtotime('-' . self::STATS_DAYS . ' days'));
        $to = date('Y-m-d', strtotime('-1 day'));
        $totals = $this->statDao->rangeTotalsByOperator(array_values(array_map('intval', $ids)), $from, $to);

        foreach ($products as &$product) {
            $productTotals = $totals[(int) ($ids[$product['product_code']] ?? 0)] ?? [];
            $categoryId = $models->get($product['product_code'])?->category_id;
            $product['category_id'] = $categoryId;
            $product['category_name'] = $categoryId === null ? null : ($categoryNames[$categoryId] ?? null);
            $product['operators'] = [];
            foreach ($product['prices'] as $operator => $price) {
                $t = $productTotals[$operator] ?? null;
                $product['operators'][] = ['operator' => $operator, 'price' => $price] + ($t === null
                    ? ['order_count' => 0, 'success_rate' => null, 'avg_duration' => null]
                    : array_intersect_key(
                        ProductStatsAdminService::summarize($t['order_count'], $t['success_count'], $t['failed_count'], $t['total_duration']),
                        array_flip(['order_count', 'success_rate', 'avg_duration'])
                    ));
            }
        }
        unset($product);

        return ['from' => $from, 'to' => $to, 'products' => $products, 'categories' => $categories];
    }

    /**
     * @param list<int> $ids
     * @return Collection<int, Product>
     */
    private function productModels(array $ids): Collection
    {
        return $this->productDao->newQuery()
            ->whereIn('id', $ids)
            ->where('status', 'active')
            ->orderBy('face_value')
            ->orderBy('id')
            ->get();
    }
}
