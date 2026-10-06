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

namespace App\Support;

use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;

/**
 * 简单的 Redis 互斥锁（SET NX EX），释放时只删自己加的锁。
 */
class RedisLock
{
    #[Inject]
    protected Redis $redis;

    /**
     * @return null|string 拿到锁返回令牌（释放时要用），被别人占着返回 null
     */
    public function acquire(string $key, int $ttlSeconds): ?string
    {
        $token = bin2hex(random_bytes(8));

        return $this->redis->set('lock:' . $key, $token, ['nx', 'ex' => $ttlSeconds]) ? $token : null;
    }

    public function release(string $key, string $token): void
    {
        $script = <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
end
return 0
LUA;
        $this->redis->eval($script, ['lock:' . $key, $token], 1);
    }

    /**
     * 只占一次，不主动释放，到期自动消失（用于防重放之类）。
     */
    public function once(string $key, int $ttlSeconds): bool
    {
        return (bool) $this->redis->set('once:' . $key, '1', ['nx', 'ex' => $ttlSeconds]);
    }
}
