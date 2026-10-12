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

use App\Model\MerchantReceivingAccount;

class MerchantReceivingAccountDao extends AbstractDao
{
    protected string $model = MerchantReceivingAccount::class;

    public function find(int $id): ?MerchantReceivingAccount
    {
        return MerchantReceivingAccount::find($id);
    }

    /**
     * @return list<int> 分配给这个商户的收款账户 id
     */
    public function accountIdsForMerchant(int $merchantId): array
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->pluck('receiving_account_id')->map(static fn ($id) => (int) $id)->all();
    }

    /**
     * @param list<int> $accountIds
     * @return array<int, list<int>> 收款账户 id => 分配到的商户 id
     */
    public function merchantIdsByAccount(array $accountIds): array
    {
        $result = [];
        if ($accountIds === []) {
            return $result;
        }
        foreach ($this->newQuery()->whereIn('receiving_account_id', $accountIds)->get(['merchant_id', 'receiving_account_id']) as $row) {
            $result[$row->receiving_account_id][] = $row->merchant_id;
        }

        return $result;
    }

    /**
     * 把这个商户能用的收款账户整体替换成 $accountIds（商户中心按商户配置用）。
     *
     * @param list<int> $accountIds
     */
    public function replaceAccountsForMerchant(int $merchantId, array $accountIds): void
    {
        $this->newQuery()->where('merchant_id', $merchantId)->whereNotIn('receiving_account_id', $accountIds ?: [0])->delete();
        $existing = $this->accountIdsForMerchant($merchantId);
        foreach (array_diff($accountIds, $existing) as $accountId) {
            MerchantReceivingAccount::create(['merchant_id' => $merchantId, 'receiving_account_id' => $accountId, 'created_at' => date('Y-m-d H:i:s')]);
        }
    }

    /**
     * 把这个收款账户分配给的商户整体替换成 $merchantIds。
     *
     * @param list<int> $merchantIds
     */
    public function replaceMerchants(int $accountId, array $merchantIds): void
    {
        $this->newQuery()->where('receiving_account_id', $accountId)->whereNotIn('merchant_id', $merchantIds ?: [0])->delete();
        $existing = $this->newQuery()->where('receiving_account_id', $accountId)->pluck('merchant_id')->map(static fn ($id) => (int) $id)->all();
        foreach (array_diff($merchantIds, $existing) as $merchantId) {
            MerchantReceivingAccount::create(['merchant_id' => $merchantId, 'receiving_account_id' => $accountId, 'created_at' => date('Y-m-d H:i:s')]);
        }
    }
}
