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

use App\Model\SystemSetting;

class SystemSettingDao extends AbstractDao
{
    protected string $model = SystemSetting::class;

    public function find(int $id): ?SystemSetting
    {
        return SystemSetting::find($id);
    }

    public function findByKey(string $key): ?SystemSetting
    {
        return $this->newQuery()->where('key', $key)->first();
    }
}
