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

use App\Model\ProductCategory;

class ProductCategoryDao extends AbstractDao
{
    protected string $model = ProductCategory::class;

    public function find(int $id): ?ProductCategory
    {
        return ProductCategory::find($id);
    }

    /**
     * @param list<int> $ids
     * @return array<int, string> id => 名称
     */
    public function namesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->newQuery()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
