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
use App\Dao\ProductCategoryDao;
use App\Dao\ProductDao;
use App\Model\AdminUser;
use App\Model\ProductCategory;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商品中心 - 商品分类：只给平台商品用，新建 / 编辑平台商品时必须选。
 *
 * - 分类不多，列表不分页，按排序值、id 排；
 * - 还有平台商品在用的分类不能删除。
 */
class ProductCategoryAdminService extends AbstractService
{
    use ValidatesAdminInput;

    private const MODULE = 'product';

    #[Inject]
    protected ProductCategoryDao $categoryDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $categories = $this->categoryDao->newQuery()->orderBy('sort')->orderBy('id')->get()->all();
        $counts = $this->productDao->countByCategory(array_map(static fn (ProductCategory $c) => $c->id, $categories));

        return array_map(fn (ProductCategory $c) => $this->format($c, $counts[$c->id] ?? 0), $categories);
    }

    /**
     * @param array<string, mixed> $data name / sort / remark
     * @return array<string, mixed>
     */
    public function create(AdminUser $operator, array $data, ?string $ip): array
    {
        $name = $this->uniqueName($data['name'] ?? null, null);
        $category = $this->categoryDao->create([
            'name' => $name,
            'sort' => $this->sort($data['sort'] ?? 0),
            'remark' => $this->remark($data['remark'] ?? null),
        ]);
        $after = $this->format($category, 0);
        $this->operationLogDao->record($operator->id, self::MODULE, 'create_product_category', 'product_category', $category->id, null, $after, $ip);

        return $after;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(AdminUser $operator, int $id, array $data, ?string $ip): array
    {
        $category = $this->findOrFail($id);
        $count = $this->productDao->countByCategory([$category->id])[$category->id] ?? 0;
        $before = $this->format($category, $count);
        $attrs = [];
        if (array_key_exists('name', $data)) {
            $attrs['name'] = $this->uniqueName($data['name'], $category->id);
        }
        if (array_key_exists('sort', $data)) {
            $attrs['sort'] = $this->sort($data['sort']);
        }
        if (array_key_exists('remark', $data)) {
            $attrs['remark'] = $this->remark($data['remark']);
        }
        if ($attrs !== []) {
            $this->categoryDao->update($category->id, $attrs);
        }
        $after = $this->format($this->findOrFail($category->id), $count);
        $this->operationLogDao->record($operator->id, self::MODULE, 'update_product_category', 'product_category', $category->id, $before, $after, $ip);

        return $after;
    }

    public function delete(AdminUser $operator, int $id, ?string $ip): void
    {
        $category = $this->findOrFail($id);
        $count = $this->productDao->countByCategory([$category->id])[$category->id] ?? 0;
        if ($count > 0) {
            throw new HttpException(422, "还有 {$count} 个平台商品在用「{$category->name}」，先把它们改到别的分类再删除");
        }
        $this->categoryDao->delete($category->id);
        $this->operationLogDao->record($operator->id, self::MODULE, 'delete_product_category', 'product_category', $category->id, $this->format($category, 0), null, $ip);
    }

    /**
     * 平台商品选的分类必须存在。
     */
    public function assertExists(mixed $id): int
    {
        if (! is_numeric($id) || (int) $id != $id || $this->categoryDao->find((int) $id) === null) {
            throw new HttpException(422, '请选择商品分类');
        }

        return (int) $id;
    }

    private function findOrFail(int $id): ProductCategory
    {
        $category = $this->categoryDao->find($id);
        if (! $category) {
            throw new HttpException(404, '商品分类不存在');
        }

        return $category;
    }

    private function uniqueName(mixed $value, ?int $exceptId): string
    {
        $name = $this->requiredString($value, '分类名称', 32);
        $exists = $this->categoryDao->newQuery()->where('name', $name)
            ->when($exceptId !== null, static fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
        if ($exists) {
            throw new HttpException(422, "分类「{$name}」已存在");
        }

        return $name;
    }

    private function sort(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (! is_numeric($value) || (int) $value != $value || $value < 0 || $value > 9999) {
            throw new HttpException(422, '排序必须是 0~9999 的整数');
        }

        return (int) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(ProductCategory $c, int $productCount): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'sort' => $c->sort,
            'remark' => $c->remark,
            'product_count' => $productCount,
            'created_at' => $c->created_at?->toDateTimeString(),
            'updated_at' => $c->updated_at?->toDateTimeString(),
        ];
    }
}
