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

use App\Model\OrderAttempt;
use Hyperf\Database\Model\Collection;

class OrderAttemptDao extends AbstractDao
{
    protected string $model = OrderAttempt::class;

    public function find(int $id): ?OrderAttempt
    {
        return OrderAttempt::find($id);
    }

    /**
     * @return Collection<int, OrderAttempt>
     */
    public function forOrder(int $orderId): Collection
    {
        return $this->newQuery()->where('order_id', $orderId)->orderBy('id')->get();
    }

    /**
     * 按提交单号（传给供应商的订单号）找订单 id，后台搜索框用。
     */
    public function orderIdForAttemptNo(string $attemptNo): ?int
    {
        $orderId = $this->newQuery()->where('attempt_no', $attemptNo)->value('order_id');

        return $orderId === null ? null : (int) $orderId;
    }

    /**
     * 这些订单里上游返回不明确的：还在处理中的提交带着说明（如「下单未确认受理」「查单未拿到明确结果」），正常受理的不带。
     *
     * @param list<int> $orderIds
     * @return array<int, string> order_id => 说明
     */
    public function unclearProcessing(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return $this->newQuery()
            ->whereIn('order_id', $orderIds)
            ->where('status', OrderAttempt::STATUS_PROCESSING)
            ->whereNotNull('message')
            ->where('message', '<>', '')
            ->orderBy('id')
            ->pluck('message', 'order_id')
            ->all();
    }

    public function findForSupplier(int $supplierId, ?string $attemptNo, ?string $supplierOrderNo): ?OrderAttempt
    {
        if ($attemptNo !== null && $attemptNo !== '') {
            return $this->newQuery()->where('attempt_no', $attemptNo)->where('supplier_id', $supplierId)->first();
        }
        if ($supplierOrderNo !== null && $supplierOrderNo !== '') {
            return $this->newQuery()->where('supplier_id', $supplierId)->where('supplier_order_no', $supplierOrderNo)->first();
        }

        return null;
    }

    /**
     * 这个供应商还有没有提交了还没出结果的单（删除供应商前检查）。
     */
    /**
     * 这笔订单还有没有提交了还没出结果的。
     */
    public function hasProcessingForOrder(int $orderId): bool
    {
        return $this->newQuery()->where('order_id', $orderId)->where('status', OrderAttempt::STATUS_PROCESSING)->exists();
    }

    public function hasProcessingForSupplier(int $supplierId): bool
    {
        return $this->newQuery()->where('status', OrderAttempt::STATUS_PROCESSING)->where('supplier_id', $supplierId)->exists();
    }

    /**
     * 这个供应商商品还有没有提交了还没出结果的单（删除供应商商品前检查）。
     */
    public function hasProcessingForSupplierProduct(int $supplierProductId): bool
    {
        return $this->newQuery()->where('status', OrderAttempt::STATUS_PROCESSING)->where('supplier_product_id', $supplierProductId)->exists();
    }

    /**
     * 带条件的状态变更，返回是否改成功。
     *
     * @param array<string, mixed> $attrs
     */
    public function transition(int $id, string $from, string $to, array $attrs = []): bool
    {
        return $this->newQuery()->where('id', $id)->where('status', $from)->update(['status' => $to] + $attrs) === 1;
    }

    /**
     * 提交超过一段时间还没结果、且距离上次查单也超过间隔的尝试，按提交时间排。
     *
     * @return Collection<int, OrderAttempt>
     */
    public function dueForQuery(string $submittedBefore, string $queriedBefore, int $limit): Collection
    {
        return $this->newQuery()
            ->where('status', OrderAttempt::STATUS_PROCESSING)
            ->where('submitted_at', '<=', $submittedBefore)
            ->where(fn ($q) => $q->whereNull('last_queried_at')->orWhere('last_queried_at', '<=', $queriedBefore))
            ->orderBy('submitted_at')
            ->limit($limit)
            ->get();
    }
}
