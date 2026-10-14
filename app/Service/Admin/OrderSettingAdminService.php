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

namespace App\Service\Admin;

use App\Dao\AdminOperationLogDao;
use App\Model\AdminUser;
use App\Service\AbstractService;
use App\Service\Setting\OrderSettingService;
use App\Service\Setting\SettingService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统配置 - 下单设置：重复充值拦截的时间窗口（见 OrderSettingService）。
 */
class OrderSettingAdminService extends AbstractService
{
    private const MODULE = 'system';

    /** 最长一天 */
    private const MAX_WINDOW_MINUTES = 1440;

    #[Inject]
    protected SettingService $settingService;

    #[Inject]
    protected OrderSettingService $orderSettingService;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @return array{duplicate_window_minutes: int}
     */
    public function get(): array
    {
        return ['duplicate_window_minutes' => $this->orderSettingService->duplicateWindowMinutes()];
    }

    /**
     * @param array<string, mixed> $data duplicate_window_minutes：0 ~ 1440，0 表示不拦截
     * @return array{duplicate_window_minutes: int}
     */
    public function save(AdminUser $operator, array $data, ?string $ip): array
    {
        $minutes = $data['duplicate_window_minutes'] ?? null;
        if (! is_numeric($minutes) || (int) $minutes != $minutes || (int) $minutes < 0 || (int) $minutes > self::MAX_WINDOW_MINUTES) {
            throw new HttpException(422, '重复充值拦截时间填 0 ~ ' . self::MAX_WINDOW_MINUTES . ' 的整数分钟，0 表示不拦截');
        }
        $before = $this->get();
        $after = ['duplicate_window_minutes' => (int) $minutes];
        $this->settingService->set(OrderSettingService::KEY, $after);
        $this->orderSettingService->flush();
        $this->operationLogDao->record($operator->id, self::MODULE, 'update_order_setting', 'system_setting', null, $before, $after, $ip);

        return $this->get();
    }
}
