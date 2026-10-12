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

use App\Model\Product;

class ProductDao extends AbstractDao
{
    protected string $model = Product::class;

    public function find(int $id): ?Product
    {
        return Product::find($id);
    }

    /**
     * @param list<int> $categoryIds
     * @return array<int, int> 分类 id => 平台商品数
     */
    public function countByCategory(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return $this->newQuery()->whereIn('category_id', $categoryIds)->groupBy('category_id')
            ->selectRaw('category_id, count(*) as total')->pluck('total', 'category_id')
            ->map(static fn ($n) => (int) $n)->all();
    }
}
