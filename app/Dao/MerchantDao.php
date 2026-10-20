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

class MerchantDao extends AbstractDao
{
    protected string $model = Merchant::class;

    public function find(int $id): ?Merchant
    {
        return Merchant::find($id);
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
}
