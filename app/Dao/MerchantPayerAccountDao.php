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

use App\Model\MerchantPayerAccount;

class MerchantPayerAccountDao extends AbstractDao
{
    protected string $model = MerchantPayerAccount::class;

    public function find(int $id): ?MerchantPayerAccount
    {
        return MerchantPayerAccount::find($id);
    }

    /**
     * 带条件的状态变更（审核时防并发、防审核到商户刚改过的内容），返回是否改成功。
     *
     * @param array<string, mixed> $attrs
     */
    public function transition(int $id, string $from, string $to, array $attrs = []): bool
    {
        return $this->newQuery()->where('id', $id)->where('status', $from)->update(['status' => $to] + $attrs) === 1;
    }
}
