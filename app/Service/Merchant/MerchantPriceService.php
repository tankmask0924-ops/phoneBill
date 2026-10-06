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
use App\Service\AbstractService;
use Hyperf\Cache\Annotation\Cacheable;
use Hyperf\Cache\Annotation\CacheEvict;
use Hyperf\Di\Annotation\Inject;

/**
 * 下单时「这个商户买这个商品是什么价」，按商户 + 商品编码缓存 5 分钟。
 *
 * 平台商品新建 / 修改 / 上下架、商户开通商品或改价之后，在事务提交后调 flush() 全部清掉
 * （见 ProductAdminService、MerchantProductAdminService）。这些操作不频繁，整体清最不容易出错。
 */
class MerchantPriceService extends AbstractService
{
    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected MerchantProductDao $merchantProductDao;

    #[Inject]
    protected MerchantProductPriceDao $merchantPriceDao;

    /**
     * 商品不存在返回 null；商品下架、没开通等由调用方看 product_active / opened_active 判断。
     *
     * @return null|array{product_id: int, product_code: string, product_name: string, face_value: int, product_active: bool, opened_active: bool, prices: array<string, string>}
     */
    #[Cacheable(prefix: 'merchant_price', value: '#{merchantId}:#{productCode}', ttl: 300)]
    public function quote(int $merchantId, string $productCode): ?array
    {
        $product = $this->productDao->newQuery()->where('code', $productCode)->first();
        if ($product === null) {
            return null;
        }
        $opened = $this->merchantProductDao->findFor($merchantId, $product->id);

        return [
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'face_value' => $product->face_value,
            'product_active' => $product->status === 'active',
            'opened_active' => $opened !== null && $opened->status === 'active',
            'prices' => $opened === null ? [] : ($this->merchantPriceDao->pricesFor([$opened->id])[$opened->id] ?? []),
        ];
    }

    #[CacheEvict(prefix: 'merchant_price', all: true)]
    public function flush(): void
    {
    }
}
