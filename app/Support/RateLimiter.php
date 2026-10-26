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
 * 滑动窗口限流（Redis 有序集合）：任意连续 $seconds 秒内最多放行 $limit 次。
 * 先清掉窗口外的记录再计数，没满才记一笔，整个过程在一个 Lua 脚本里，并发也不会多放。
 */
class RateLimiter
{
    private const SCRIPT = <<<'LUA'
redis.call('zremrangebyscore', KEYS[1], '-inf', tonumber(ARGV[1]) - tonumber(ARGV[2]))
if redis.call('zcard', KEYS[1]) >= tonumber(ARGV[3]) then
    return 0
end
redis.call('zadd', KEYS[1], ARGV[1], ARGV[4])
redis.call('pexpire', KEYS[1], ARGV[2])
return 1
LUA;

    #[Inject]
    protected Redis $redis;

    /**
     * 放行返回 true 并记一次；窗口内已经满了返回 false（不记）。
     */
    public function attempt(string $key, int $limit, int $seconds): bool
    {
        $now = (int) floor(microtime(true) * 1000);

        return (int) $this->redis->eval(self::SCRIPT, ['rate:' . $key, $now, $seconds * 1000, $limit, $now . ':' . bin2hex(random_bytes(4))], 1) === 1;
    }
}
