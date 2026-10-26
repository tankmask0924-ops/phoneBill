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

    public function findByAlipayOrderNo(string $alipayOrderNo): ?MerchantOnlinePayment
    {
        return $this->newQuery()->where('alipay_order_no', $alipayOrderNo)->first();
    }

    public function pendingCountForMerchant(int $merchantId): int
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->where('status', MerchantOnlinePayment::STATUS_PENDING)->count();
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
}
