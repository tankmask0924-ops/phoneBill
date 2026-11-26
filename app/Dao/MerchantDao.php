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

use App\Model\Merchant;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Collection;
use Hyperf\DbConnection\Db;

class MerchantDao extends AbstractDao
{
    public const DELETED_SUFFIX = '（已删除）';

    protected string $model = Merchant::class;

    public function find(int $id): ?Merchant
    {
        return Merchant::find($id);
    }

    /**
     * 含已删除的商户（订单通知、历史记录用）。
     */
    public function findWithTrashed(int $id): ?Merchant
    {
        return Merchant::withTrashed()->find($id);
    }

    /**
     * 历史记录（订单、流水、加款）显示商户名用，含已删除的，已删除的名字后面带「（已删除）」。
     *
     * @param list<int> $ids
     * @return array<int, string> id => 名称
     */
    public function namesWithTrashed(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Merchant::withTrashed()->whereIn('id', $ids)->get(['id', 'name', 'deleted_at'])
            ->mapWithKeys(static fn (Merchant $m) => [$m->id => $m->name . ($m->deleted_at === null ? '' : self::DELETED_SUFFIX)])
            ->all();
    }

    /**
     * 订单筛选的商户下拉：含已删除的（还能按商户查历史订单），已删除的排在后面、名字带「（已删除）」。
     *
     * @return list<array{id: int, name: string}>
     */
    public function optionsWithTrashed(): array
    {
        return Merchant::withTrashed()->orderByRaw('deleted_at is not null')->orderBy('id')->get(['id', 'name', 'deleted_at'])
            ->map(static fn (Merchant $m) => ['id' => $m->id, 'name' => $m->name . ($m->deleted_at === null ? '' : self::DELETED_SUFFIX)])
            ->values()->all();
    }

    /**
     * 商户名称有没有被用过，含已删除的（数据库唯一索引也算已删除的）。
     */
    public function nameTaken(string $name, ?int $exceptId = null): bool
    {
        return Merchant::withTrashed()->where('name', $name)->when($exceptId !== null, static fn ($q) => $q->where('id', '!=', $exceptId))->exists();
    }

    public function findByAppKey(string $appKey): ?Merchant
    {
        return $this->newQuery()->where('app_key', $appKey)->first();
    }

    /**
     * 事务里锁住商户行，改余额前必须先调这里。
     */
    public function lockForUpdate(int $id): ?Merchant
    {
        return $this->newQuery()->where('id', $id)->lockForUpdate()->first();
    }

    /**
     * 商户指定的支付宝转账加款账户（直接查库，不用缓存的商户对象）。
     */
    public function alipayAccountId(int $id): ?int
    {
        $value = $this->newQuery()->where('id', $id)->value('alipay_account_id');

        return $value === null ? null : (int) $value;
    }

    public function setAlipayAccountId(int $id, ?int $accountId): void
    {
        $this->newQuery()->where('id', $id)->update(['alipay_account_id' => $accountId]);
    }

    /**
     * 余额回到阈值以上（或关了提醒）的，重新开启下一次跌破时的提醒。
     *
     * @return int 重新开启了几个
     */
    public function rearmBalanceAlerts(): int
    {
        return $this->newQuery()
            ->where('balance_alert_armed', false)
            ->where(static fn ($q) => $q->whereNull('balance_alert_threshold')->orWhereColumn('balance', '>=', 'balance_alert_threshold'))
            ->update(['balance_alert_armed' => true]);
    }

    /**
     * 该发余额提醒的商户：启用中、设了阈值、余额低于阈值、开启着提醒、今天还没发满 $dailyLimit 次。
     *
     * @return Collection<int, Merchant>
     */
    public function dueForBalanceAlert(string $today, int $dailyLimit): Collection
    {
        return $this->whereAlertQuota($this->newQuery(), $today, $dailyLimit)
            ->where('status', 'active')
            ->whereNotNull('balance_alert_threshold')
            ->whereColumn('balance', '<', 'balance_alert_threshold')
            ->where('balance_alert_armed', true)
            ->get(['id', 'name', 'balance', 'balance_alert_threshold', 'balance_alert_date', 'balance_alert_count']);
    }

    /**
     * 占住这一次提醒：暂停提醒、当天次数加 1。多台 / 并发时只有一个能占到。
     */
    public function claimBalanceAlert(int $id, string $today, int $dailyLimit): bool
    {
        // MySQL 按顺序赋值，后面的能看到前面改过的值：先算次数，再改日期
        return $this->whereAlertQuota($this->newQuery()->where('id', $id)->where('balance_alert_armed', true), $today, $dailyLimit)
            ->update([
                'balance_alert_armed' => false,
                'balance_alert_count' => Db::raw("case when balance_alert_date = '{$today}' then balance_alert_count + 1 else 1 end"),
                'balance_alert_date' => $today,
            ]) === 1;
    }

    /**
     * 一条都没发出去时还原，下一轮再试。
     */
    public function releaseBalanceAlert(int $id, string $today, ?string $previousDate, int $previousCount): void
    {
        $this->newQuery()
            ->where('id', $id)
            ->where('balance_alert_armed', false)
            ->where('balance_alert_date', $today)
            ->update(['balance_alert_armed' => true, 'balance_alert_date' => $previousDate, 'balance_alert_count' => $previousCount]);
    }

    /**
     * @return list<array{id: int, consumed_until: null|string}>
     */
    public function consumptionCursors(): array
    {
        return $this->newQuery()->orderBy('id')->get(['id', 'consumed_until'])
            ->map(static fn (Merchant $m) => ['id' => $m->id, 'consumed_until' => $m->consumed_until?->toDateString()])
            ->all();
    }

    /**
     * 累计消费结转：consumed_until 还是 $from 时才加，防止重复结转。
     */
    public function settleConsumption(int $id, ?string $from, string $until, string $amount): bool
    {
        $builder = $this->newQuery()->where('id', $id);
        $from === null ? $builder->whereNull('consumed_until') : $builder->where('consumed_until', $from);

        return $builder->update([
            'consumed_total' => Db::raw('consumed_total + ' . bcadd($amount, '0', 2)),
            'consumed_until' => $until,
        ]) === 1;
    }

    private function whereAlertQuota(Builder $builder, string $today, int $dailyLimit): Builder
    {
        return $builder->where(static fn ($q) => $q->whereNull('balance_alert_date')
            ->orWhere('balance_alert_date', '<', $today)
            ->orWhere('balance_alert_count', '<', $dailyLimit));
    }
}
