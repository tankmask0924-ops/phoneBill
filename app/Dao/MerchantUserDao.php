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

use App\Dao\Concerns\CountsLoginFailures;
use App\Model\MerchantUser;

class MerchantUserDao extends AbstractDao
{
    use CountsLoginFailures;

    protected string $model = MerchantUser::class;

    public function find(int $id): ?MerchantUser
    {
        return MerchantUser::find($id);
    }

    public function findByUsername(string $username): ?MerchantUser
    {
        return $this->newQuery()->where('username', $username)->first();
    }

    /**
     * 账号名有没有被用过，含已删除的（数据库唯一索引也算已删除的）。
     */
    public function usernameTaken(string $username): bool
    {
        return MerchantUser::withTrashed()->where('username', $username)->exists();
    }

    /**
     * 软删除商户名下的全部账号（删除商户时调用）。
     *
     * @return int 删了几个
     */
    public function deleteForMerchant(int $merchantId): int
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->delete();
    }

    /**
     * 商户下启用中的账号绑定的手机号（去重）。
     *
     * @return list<string>
     */
    public function activeMobilesOfMerchant(int $merchantId): array
    {
        return $this->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('status', 'active')
            ->whereNotNull('mobile')
            ->where('mobile', '<>', '')
            ->distinct()
            ->pluck('mobile')
            ->all();
    }
}
