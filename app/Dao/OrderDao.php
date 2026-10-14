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

use App\Model\Order;
use App\Model\OrderAttempt;
use Hyperf\DbConnection\Db;

class OrderDao extends AbstractDao
{
    protected string $model = Order::class;

    public function find(int $id): ?Order
    {
        return Order::find($id);
    }

    /**
     * 订单数、成功数、成功订单的扣款合计和利润合计；$from 为空时统计全部订单。
     *
     * @return array{order_count: int, success_count: int, sales: string, profit: string}
     */
    public function aggregate(?string $from = null): array
    {
        $row = $this->newQuery()
            ->when($from !== null, static fn ($q) => $q->where('created_at', '>=', $from))
            ->selectRaw(
                "count(*) as order_count,
                coalesce(sum(status = 'success'), 0) as success_count,
                coalesce(sum(case when status = 'success' then sale_price end), 0) as sales,
                coalesce(sum(case when status = 'success' then profit end), 0) as profit"
            )->toBase()->first();

        return [
            'order_count' => (int) $row->order_count,
            'success_count' => (int) $row->success_count,
            'sales' => bcadd((string) $row->sales, '0', 2),
            'profit' => bcadd((string) $row->profit, '0', 2),
        ];
    }

    public function findByOrderNo(string $orderNo): ?Order
    {
        return $this->newQuery()->where('order_no', $orderNo)->first();
    }

    /**
     * 这个商户下平台订单号或商户订单号等于 $no 的订单 id（两个都走唯一索引）。
     *
     * @return list<int>
     */
    public function idsForMerchantByNo(int $merchantId, string $no): array
    {
        // 分两次查，各走各的唯一索引；合成一条 OR 可能只用上 merchant_id 前缀，扫这个商户的全部订单
        $ids = [
            $this->newQuery()->where('order_no', $no)->where('merchant_id', $merchantId)->value('id'),
            $this->newQuery()->where('merchant_id', $merchantId)->where('merchant_order_no', $no)->value('id'),
        ];

        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }

    public function findByMerchantOrderNo(int $merchantId, string $merchantOrderNo): ?Order
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->where('merchant_order_no', $merchantOrderNo)->first();
    }

    /**
     * 带条件的状态变更：只有当前状态在 $from 里才改，返回是否改成功（并发时只有一方成功）。
     * $to 必须和 $from 里的状态都不一样：MySQL 对值没变的行不算影响行数，会被当成没改成功。
     *
     * @param list<string> $from
     * @param array<string, mixed> $attrs 一起更新的其他字段
     */
    public function transition(int $id, array $from, string $to, array $attrs = []): bool
    {
        return $this->newQuery()->where('id', $id)->whereIn('status', $from)->update(['status' => $to] + $attrs) === 1;
    }

    /**
     * 待提交 / 处理中、但没有任何处理中的提交、且 $before 之后没动过的订单（提交任务丢了）。
     *
     * @return list<int>
     */
    public function stuckIds(string $before, int $limit): array
    {
        return $this->newQuery()
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->where('updated_at', '<=', $before)
            ->whereNotExists(fn ($q) => $q->select(Db::raw(1))
                ->from('order_attempts as a')
                ->whereColumn('a.order_id', 'orders.id')
                ->where('a.status', OrderAttempt::STATUS_PROCESSING))
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * 同一手机号、同一面值，在 $since 之后有没有还没出结果的订单。
     *
     * @param list<string> $statuses
     */
    public function hasRecent(string $mobile, int $faceValue, string $since, array $statuses): bool
    {
        return $this->newQuery()
            ->where('mobile', $mobile)
            ->where('created_at', '>=', $since)
            ->where('face_value', $faceValue)
            ->whereIn('status', $statuses)
            ->exists();
    }

    /**
     * hasRecent() 的批量版：这些手机号里，同一面值在 $since 之后有还没出结果的订单的那些。
     *
     * @param list<string> $mobiles
     * @param list<string> $statuses
     * @return list<string>
     */
    public function recentMobiles(array $mobiles, int $faceValue, string $since, array $statuses): array
    {
        if ($mobiles === []) {
            return [];
        }

        return $this->newQuery()
            ->whereIn('mobile', $mobiles)
            ->where('created_at', '>=', $since)
            ->where('face_value', $faceValue)
            ->whereIn('status', $statuses)
            ->distinct()
            ->pluck('mobile')
            ->all();
    }
}
