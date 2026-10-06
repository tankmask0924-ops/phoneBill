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

    /**
     * 分批取出全部号码。
     *
     * @param callable(list<string>): void $callback
     */
    public function eachMobileChunk(int $size, callable $callback): void
    {
        $this->newQuery()->select(['id', 'mobile'])->chunkById($size, static function ($rows) use ($callback) {
            $callback($rows->pluck('mobile')->all());
        });
    }
}
