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

namespace App\Service\Merchant;

use App\Dao\MerchantDao;
use App\Model\Merchant;
use App\Service\AbstractService;
use Hyperf\Cache\Annotation\Cacheable;
use Hyperf\Cache\Annotation\CacheEvict;
use Hyperf\Di\Annotation\Inject;

/**
 * 开放接口每个请求都要按 AppKey 找商户，按 AppKey 缓存 5 分钟。
 *
 * - 不缓存余额（每单都在变），要余额的地方直接查库；AppSecret 缓存的是密文，解密在进程里做；
 * - Merchant 保存时自动清掉（见 Merchant::saved()），改资料、停用、重置密钥立即生效。
 */
class MerchantLookupService extends AbstractService
{
    private const CACHED_FIELDS = ['id', 'name', 'app_key', 'app_secret', 'notify_url', 'ip_whitelist', 'status'];

    #[Inject]
    protected MerchantDao $merchantDao;

    /**
     * 返回的 Merchant 没有 balance，不要拿它改数据。
     */
    public function findByAppKey(string $appKey): ?Merchant
    {
        $attributes = $this->profile($appKey);
        if ($attributes === null) {
            return null;
        }
        $merchant = new Merchant();
        $merchant->setRawAttributes($attributes, true);
        $merchant->exists = true;

        return $merchant;
    }

    /**
     * @return null|array<string, mixed>
     */
    #[Cacheable(prefix: 'merchant_by_app_key', value: '#{appKey}', ttl: 300)]
    public function profile(string $appKey): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $appKey) !== 1) {
            return null;
        }

        return $this->merchantDao->findByAppKey($appKey)?->only(self::CACHED_FIELDS);
    }

    #[CacheEvict(prefix: 'merchant_by_app_key', value: '#{appKey}')]
    public function forget(string $appKey): void
    {
    }
}
