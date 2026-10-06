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

use App\Model\MerchantUser;

class MerchantUserDao extends AbstractDao
{
    protected string $model = MerchantUser::class;

    public function find(int $id): ?MerchantUser
    {
        return MerchantUser::find($id);
    }

    public function findByUsername(string $username): ?MerchantUser
    {
        return $this->newQuery()->where('username', $username)->first();
    }
}
