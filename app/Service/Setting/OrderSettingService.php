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
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;

/**
 * 下单相关的系统设置（后台「系统配置 - 下单设置」改，见 OrderSettingAdminService）。每笔下单都要读，缓存起来，保存时清掉。
 */
class OrderSettingService extends AbstractService
{
    public const KEY = 'order';

    #[Inject]
    protected SettingService $settingService;

    #[Inject]
    protected ConfigInterface $config;

    /**
     * 重复充值拦截的时间窗口（分钟）：同一号码、同一面值在这段时间内有进行中的订单就拒绝下单，0 表示不拦截。
     * 后台没设置过时用 order.duplicate_window_minutes（环境变量 ORDER_DUPLICATE_WINDOW_MINUTES）。
     */
    #[Cacheable(prefix: 'order_setting', value: 'duplicate_window', ttl: 300)]
    public function duplicateWindowMinutes(): int
    {
        $minutes = $this->settingService->get(self::KEY)['duplicate_window_minutes'] ?? null;

        return is_int($minutes) ? $minutes : (int) $this->config->get('order.duplicate_window_minutes', 10);
    }

    #[CacheEvict(prefix: 'order_setting', value: 'duplicate_window')]
    public function flush(): void
    {
    }
}
