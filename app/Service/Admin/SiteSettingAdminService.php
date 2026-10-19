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
use App\Service\Setting\SiteSettingService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统配置 - 基本设置：系统名称（见 SiteSettingService）。留空表示用打包时的默认名称。
 */
class SiteSettingAdminService extends AbstractService
{
    private const MODULE = 'system';

    private const MAX_NAME_LENGTH = 30;

    #[Inject]
    protected SettingService $settingService;

    #[Inject]
    protected SiteSettingService $siteSettingService;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @return array{name: string}
     */
    public function get(): array
    {
        return ['name' => $this->siteSettingService->name()];
    }

    /**
     * @param array<string, mixed> $data name：0 ~ 30 个字，留空用默认名称
     * @return array{name: string}
     */
    public function save(AdminUser $operator, array $data, ?string $ip): array
    {
        $name = trim(is_string($data['name'] ?? null) ? $data['name'] : '');
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new HttpException(422, '系统名称最多 ' . self::MAX_NAME_LENGTH . ' 个字');
        }
        $before = $this->get();
        $this->settingService->set(SiteSettingService::KEY, ['name' => $name]);
        $this->siteSettingService->flush();
        $after = $this->get();
        $this->operationLogDao->record($operator->id, self::MODULE, 'update_site_setting', 'system_setting', null, $before, $after, $ip);

        return $after;
    }
}
