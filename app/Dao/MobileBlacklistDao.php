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

use App\Model\MobileBlacklist;

class MobileBlacklistDao extends AbstractDao
{
    protected string $model = MobileBlacklist::class;

    public function find(int $id): ?MobileBlacklist
    {
        return MobileBlacklist::find($id);
    }

    public function contains(string $mobile): bool
    {
        return $this->newQuery()->where('mobile', $mobile)->exists();
    }
}
