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

namespace App\Service\Risk;

use App\Dao\MobileBlacklistDao;
use App\Service\AbstractService;
use App\Support\RedisLock;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;

/**
 * 下单时查号码黑名单：整个黑名单放在 Redis 的一个集合里，查询只问 Redis，不查数据库。
 *
 * 保证不会把黑名单号码放过去：
 * - 黑名单每次变动（MobileBlacklist 新增 / 删除）把版本号 +1（markChanged()）；
 * - 集合旁边记着它是按哪个版本建的，版本对不上、或者集合没了（Redis 重启、被清空），就从数据库重建；
 * - 重建期间别的请求拿不到重建锁，这一次直接查数据库；
 * - 建好的集合最多用 1 小时就整体重建，兜住绕过后台直接改数据库的情况。
 */
class BlacklistService extends AbstractService
{
    private const SET_KEY = 'blacklist:mobiles';

    /** 集合是按哪个版本建的，过期 = 需要重建 */
    private const BUILT_KEY = 'blacklist:built_version';

    /** 黑名单当前版本，每次变动 +1 */
    private const VERSION_KEY = 'blacklist:version';

    private const REBUILD_EVERY_SECONDS = 3600;

    private const CHUNK = 5000;

    #[Inject]
    protected MobileBlacklistDao $blacklistDao;

    #[Inject]
    protected Redis $redis;

    #[Inject]
    protected RedisLock $lock;

    public function isBlocked(string $mobile): bool
    {
        if (! $this->isFresh() && ! $this->rebuild()) {
            // 别的进程正在重建，这一次直接查库
            return $this->blacklistDao->contains($mobile);
        }

        return (bool) $this->redis->sIsMember(self::SET_KEY, $mobile);
    }

    /**
     * 黑名单变了：让集合失效，下一次查询时重建。
     */
    public function markChanged(): void
    {
        $this->redis->incr(self::VERSION_KEY);
    }

    private function isFresh(): bool
    {
        [$built, $version] = $this->redis->mGet([self::BUILT_KEY, self::VERSION_KEY]);

        return $built !== false && $built !== null && (string) $built === (string) ($version ?: '0');
    }

    /**
     * 从数据库重建集合：先建到临时 key，建好后整体替换，查询方不会看到建了一半的集合。
     *
     * @return bool 拿到重建锁并建好返回 true；别人正在建返回 false
     */
    private function rebuild(): bool
    {
        $token = $this->lock->acquire('blacklist:rebuild', 60);
        if ($token === null) {
            return false;
        }
        try {
            // 先记下版本再读库：读库期间黑名单又变了的话，版本对不上，下一次会再重建
            $version = (string) ($this->redis->get(self::VERSION_KEY) ?: '0');
            $tmpKey = self::SET_KEY . ':building:' . $token;
            $this->blacklistDao->eachMobileChunk(self::CHUNK, function (array $mobiles) use ($tmpKey) {
                $this->redis->sAdd($tmpKey, ...$mobiles);
            });
            if ($this->redis->exists($tmpKey)) {
                $this->redis->rename($tmpKey, self::SET_KEY);
            } else {
                // 黑名单是空的：Redis 里没有空集合，删掉旧的就行
                $this->redis->del(self::SET_KEY);
            }
            $this->redis->set(self::BUILT_KEY, $version, ['ex' => self::REBUILD_EVERY_SECONDS]);

            return true;
        } finally {
            $this->lock->release('blacklist:rebuild', $token);
        }
    }
}
