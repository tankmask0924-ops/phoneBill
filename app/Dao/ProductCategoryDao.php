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
     * 这些分类的 id、名称，按排序值排（下拉框、tab 用）。
     *
     * @param list<int> $ids
     * @return list<array{id: int, name: string}>
     */
    public function optionsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->newQuery()->whereIn('id', $ids)->orderBy('sort')->orderBy('id')->get(['id', 'name'])
            ->map(static fn (ProductCategory $c) => ['id' => $c->id, 'name' => $c->name])->values()->all();
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
