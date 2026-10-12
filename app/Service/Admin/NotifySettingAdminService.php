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
use App\Service\Setting\SettingService;
use App\Service\Supplier\SupplierGateway;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统配置 - 回调域名：供应商回调地址按「回调域名 + /notify/supplier/{供应商编码}」自动生成（见 SupplierGateway::notifyUrl()）。
 */
class NotifySettingAdminService extends AbstractService
{
    private const MODULE = 'system';

    #[Inject]
    protected SettingService $settingService;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @return array{base_url: string}
     */
    public function get(): array
    {
        $base = $this->settingService->get(SupplierGateway::NOTIFY_BASE_SETTING)['base_url'] ?? '';

        return ['base_url' => is_string($base) ? $base : ''];
    }

    /**
     * @return array{base_url: string}
     */
    public function save(AdminUser $operator, mixed $baseUrl, ?string $ip): array
    {
        $baseUrl = rtrim(trim(is_string($baseUrl) ? $baseUrl : ''), '/');
        if ($baseUrl === '' || mb_strlen($baseUrl) > 200 || filter_var($baseUrl, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://[^/]+$#i', $baseUrl)) {
            throw new HttpException(422, '回调域名填 http:// 或 https:// 开头的域名，不带路径，如 https://pay.example.com');
        }
        $before = $this->get();
        $this->settingService->set(SupplierGateway::NOTIFY_BASE_SETTING, ['base_url' => $baseUrl]);
        $this->operationLogDao->record($operator->id, self::MODULE, 'update_notify_setting', 'system_setting', null, $before, ['base_url' => $baseUrl], $ip);

        return $this->get();
    }
}
