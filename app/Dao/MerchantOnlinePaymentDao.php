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

use App\Model\MerchantOnlinePayment;
use Hyperf\Database\Model\Collection;

class MerchantOnlinePaymentDao extends AbstractDao
{
    protected string $model = MerchantOnlinePayment::class;

    public function find(int $id): ?MerchantOnlinePayment
    {
        return MerchantOnlinePayment::find($id);
    }

    public function findForMerchant(int $merchantId, int $id): ?MerchantOnlinePayment
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->where('id', $id)->first();
    }

    public function orderNoExists(string $orderNo): bool
    {
        return $this->newQuery()->where('order_no', $orderNo)->exists();
    }

    public function pendingCountForMerchant(int $merchantId): int
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->where('status', MerchantOnlinePayment::STATUS_PENDING)->count();
    }

    /**
     * 还在自动核对期内的待支付单。
     *
     * @return Collection<int, MerchantOnlinePayment>
     */
    public function checking(string $now): Collection
    {
        return $this->newQuery()
            ->where('status', MerchantOnlinePayment::STATUS_PENDING)
            ->where('checking_until', '>=', $now)
            ->orderBy('id')
            ->get();
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
     * 过了自动核对期还没到账的待支付单改成已过期，返回改了几条。
     */
    public function expire(string $now): int
    {
        return $this->newQuery()
            ->where('status', MerchantOnlinePayment::STATUS_PENDING)
            ->where('checking_until', '<', $now)
            ->update(['status' => MerchantOnlinePayment::STATUS_EXPIRED]);
    }

    /**
     * 已过期 / 已取消、并且过了核对期的单释放占用的金额，返回释放了几条。
     */
    public function releaseAmounts(string $now): int
    {
        return $this->newQuery()
            ->whereNotNull('active_amount')
            ->whereIn('status', [MerchantOnlinePayment::STATUS_EXPIRED, MerchantOnlinePayment::STATUS_CANCELLED])
            ->where('checking_until', '<', $now)
            ->update(['active_amount' => null]);
    }
}
