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

    /**
     * 一段时间内下单的订单，按「省份 + 运营商 + 最终供应商」汇总：成功的取成交供应商，其余取最后一次提交的，没提交过记 0。
     *
     * @return list<array{province: string, operator: string, supplier_id: int, order_count: int, success_count: int, failed_count: int, success_amount: string, profit: string}>
     */
    public function provinceSupplierTotals(string $from, string $to): array
    {
        $rows = $this->newQuery()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(
                "province, operator,
                coalesce(supplier_id, (select a.supplier_id from order_attempts a where a.order_id = orders.id order by a.id desc limit 1), 0) as final_supplier_id,
                count(*) as order_count,
                coalesce(sum(status = 'success'), 0) as success_count,
                coalesce(sum(status = 'failed'), 0) as failed_count,
                coalesce(sum(case when status = 'success' then sale_price end), 0) as success_amount,
                coalesce(sum(case when status = 'success' then profit end), 0) as profit"
            )
            ->groupBy('province', 'operator', 'final_supplier_id')
            ->toBase()
            ->get();

        return $rows->map(static fn ($row) => [
            'province' => (string) $row->province,
            'operator' => (string) $row->operator,
            'supplier_id' => (int) $row->final_supplier_id,
            'order_count' => (int) $row->order_count,
            'success_count' => (int) $row->success_count,
            'failed_count' => (int) $row->failed_count,
            'success_amount' => bcadd((string) $row->success_amount, '0', 2),
            'profit' => bcadd((string) $row->profit, '0', 2),
        ])->values()->all();
    }

    /**
     * 这个平台商品还有没有没出结果的订单（等待中、充值中、异常），删除商品前检查。
     */
    public function hasUnfinishedForProduct(int $productId): bool
    {
        return $this->newQuery()
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL])
            ->where('product_id', $productId)
            ->exists();
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
