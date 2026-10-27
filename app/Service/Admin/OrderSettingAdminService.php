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
 * 系统配置 - 下单设置：重复充值拦截的时间窗口、订单超时时间（见 OrderSettingService）。
 */
class OrderSettingAdminService extends AbstractService
{
    private const MODULE = 'system';

    /** 最长一天 */
    private const MAX_WINDOW_MINUTES = 1440;

    /** 订单超时最短 10 分钟（提交 5 分钟后才开始查单，太短来不及出结果），最长 3 天 */
    private const MIN_TIMEOUT_MINUTES = 10;

    private const MAX_TIMEOUT_MINUTES = 4320;

    #[Inject]
    protected SettingService $settingService;

    #[Inject]
    protected OrderSettingService $orderSettingService;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @return array{duplicate_window_minutes: int, timeout_minutes: int}
     */
    public function get(): array
    {
        return [
            'duplicate_window_minutes' => $this->orderSettingService->duplicateWindowMinutes(),
            'timeout_minutes' => $this->orderSettingService->timeoutMinutes(),
        ];
    }

    /**
     * 只改传了的项，没传的保持原样。
     *
     * @param array<string, mixed> $data duplicate_window_minutes：0 ~ 1440，0 表示不拦截；timeout_minutes：10 ~ 4320
     * @return array{duplicate_window_minutes: int, timeout_minutes: int}
     */
    public function save(AdminUser $operator, array $data, ?string $ip): array
    {
        $changes = [];
        if (array_key_exists('duplicate_window_minutes', $data)) {
            $changes['duplicate_window_minutes'] = self::minutes($data['duplicate_window_minutes'], 0, self::MAX_WINDOW_MINUTES)
                ?? throw new HttpException(422, '重复充值拦截时间填 0 ~ ' . self::MAX_WINDOW_MINUTES . ' 的整数分钟，0 表示不拦截');
        }
        if (array_key_exists('timeout_minutes', $data)) {
            $changes['timeout_minutes'] = self::minutes($data['timeout_minutes'], self::MIN_TIMEOUT_MINUTES, self::MAX_TIMEOUT_MINUTES)
                ?? throw new HttpException(422, '订单超时时间填 ' . self::MIN_TIMEOUT_MINUTES . ' ~ ' . self::MAX_TIMEOUT_MINUTES . ' 的整数分钟');
        }
        if ($changes === []) {
            throw new HttpException(422, '没有要保存的设置');
        }
        $before = $this->get();
        $after = $changes + $before;
        $this->settingService->set(OrderSettingService::KEY, $after);
        $this->orderSettingService->flush();
        $this->operationLogDao->record($operator->id, self::MODULE, 'update_order_setting', 'system_setting', null, $before, $after, $ip);

        return $this->get();
    }

    /**
     * 在 [min, max] 范围内的整数分钟，不合法返回 null。
     */
    private static function minutes(mixed $value, int $min, int $max): ?int
    {
        if (! is_numeric($value) || (int) $value != $value || (int) $value < $min || (int) $value > $max) {
            return null;
        }

        return (int) $value;
    }
}
