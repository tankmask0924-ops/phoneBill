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
use Hyperf\Cache\Annotation\Cacheable;
use Hyperf\Cache\Annotation\CacheEvict;
use Hyperf\Di\Annotation\Inject;

/**
 * 下单时查号码黑名单，按号码缓存 1 天（不在黑名单里的结果也缓存）。
 * MobileBlacklist 新增、删除时自动清掉对应号码（见 MobileBlacklist::saved() / deleted()）。
 */
class BlacklistService extends AbstractService
{
    #[Inject]
    protected MobileBlacklistDao $blacklistDao;

    #[Cacheable(prefix: 'blacklist', value: '#{mobile}', ttl: 86400)]
    public function isBlocked(string $mobile): bool
    {
        return $this->blacklistDao->contains($mobile);
    }

    #[CacheEvict(prefix: 'blacklist', value: '#{mobile}')]
    public function forget(string $mobile): void
    {
    }
}
