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

use App\Dao\ProductCategoryDao;
use App\Service\AbstractService;
use Hyperf\Cache\Annotation\Cacheable;
use Hyperf\Cache\Annotation\CacheEvict;
use Hyperf\Di\Annotation\Inject;

/**
 * 商品分类的号码限制配置，每单都要读，按分类缓存 5 分钟，改分类时清掉（ProductCategoryAdminService）。
 */
class CategoryMobileLimitService extends AbstractService
{
    #[Inject]
    protected ProductCategoryDao $categoryDao;

    /**
     * @return array{days: int, orders: int, processing: int} 0 表示不限；分类不存在时全是 0
     */
    #[Cacheable(prefix: 'category_mobile_limit', value: '#{categoryId}', ttl: 300)]
    public function limits(int $categoryId): array
    {
        $category = $this->categoryDao->find($categoryId);

        return [
            'days' => $category?->mobile_limit_days ?? 0,
            'orders' => $category?->mobile_limit_orders ?? 0,
            'processing' => $category?->mobile_limit_processing ?? 0,
        ];
    }

    #[CacheEvict(prefix: 'category_mobile_limit', value: '#{categoryId}')]
    public function forget(int $categoryId): void
    {
    }
}
