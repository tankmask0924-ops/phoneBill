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
use App\Dao\ProductDao;
use App\Model\Merchant;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 商户能买的商品和价格（开放接口「查商品」和商户后台「账户」页共用）。
 */
class MerchantCatalogService extends AbstractService
{
    #[Inject]
    protected MerchantProductDao $merchantProductDao;

    #[Inject]
    protected MerchantProductPriceDao $merchantPriceDao;

    #[Inject]
    protected ProductDao $productDao;

    /**
     * 已开通、且平台商品还在上架的，按面值排。
     *
     * @return list<array{product_code: string, name: string, face_value: int, prices: array<string, string>}>
     */
    public function openedProducts(Merchant $merchant): array
    {
        $opened = $this->merchantProductDao->newQuery()->where('merchant_id', $merchant->id)->where('status', 'active')->get()->keyBy('product_id');
        $prices = $this->merchantPriceDao->pricesFor($opened->pluck('id')->all());
        $products = $this->productDao->newQuery()
            ->whereIn('id', $opened->keys()->all())
            ->where('status', 'active')
            ->orderBy('face_value')
            ->orderBy('id')
            ->get();

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
}
