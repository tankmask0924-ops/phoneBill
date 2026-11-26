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

namespace App\Dao\Concerns;

/**
 * 登录账号表（admin_users、merchant_users）的连续输错密码次数 login_failures。
 * 都是带条件的单条 SQL，并发输错时计数不丢、只封禁一次。
 */
trait CountsLoginFailures
{
    /**
     * 输错一次：计数加 1，返回加完后的次数。
     */
    public function incrementLoginFailures(int $id): int
    {
        $this->newQuery()->where('id', $id)->increment('login_failures');

        return (int) $this->newQuery()->where('id', $id)->value('login_failures');
    }

    /**
     * 次数到了就把启用中的账号改成禁用；返回是否由这次调用封禁的。
     */
    public function lockAfterLoginFailures(int $id, int $maxFailures): bool
    {
        return $this->newQuery()
            ->where('id', $id)
            ->where('status', 'active')
            ->where('login_failures', '>=', $maxFailures)
            ->update(['status' => 'disabled']) === 1;
    }

    public function resetLoginFailures(int $id): void
    {
        $this->newQuery()->where('id', $id)->where('login_failures', '>', 0)->update(['login_failures' => 0]);
    }
}
