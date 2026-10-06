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
}
