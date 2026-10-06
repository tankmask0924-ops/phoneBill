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
