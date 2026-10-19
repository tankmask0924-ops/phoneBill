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

namespace App\Service\Setting;

use App\Service\AbstractService;
use Hyperf\Cache\Annotation\Cacheable;
use Hyperf\Cache\Annotation\CacheEvict;
use Hyperf\Di\Annotation\Inject;

/**
 * 站点设置（后台「系统配置 - 基本设置」改，见 SiteSettingAdminService）。管理后台每次打开都要读，缓存起来，保存时清掉。
 */
class SiteSettingService extends AbstractService
{
    public const KEY = 'site';

    #[Inject]
    protected SettingService $settingService;

    /**
     * 系统名称（管理后台左上角、登录页、浏览器标题），没设置过返回空字符串，前端用打包时的默认名称。
     */
    #[Cacheable(prefix: 'site_setting', value: 'name', ttl: 300)]
    public function name(): string
    {
        $name = $this->settingService->get(self::KEY)['name'] ?? null;

        return is_string($name) ? $name : '';
    }

    #[CacheEvict(prefix: 'site_setting', value: 'name')]
    public function flush(): void
    {
    }
}
