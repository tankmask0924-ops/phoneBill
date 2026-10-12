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

use App\Dao\SystemSettingDao;
use App\Security\Encrypter;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 系统设置：按 key 存一段 JSON，整段加密（里面可能有密钥）。读得不多，不做缓存。
 */
class SettingService extends AbstractService
{
    #[Inject]
    protected SystemSettingDao $settingDao;

    #[Inject]
    protected Encrypter $encrypter;

    /**
     * @return null|array<string, mixed> 没设置过返回 null
     */
    public function get(string $key): ?array
    {
        $row = $this->settingDao->findByKey($key);
        if ($row === null || $row->value === null) {
            return null;
        }
        $value = json_decode($this->encrypter->decrypt($row->value), true);

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function set(string $key, array $value): void
    {
        $encrypted = $this->encrypter->encrypt((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $row = $this->settingDao->findByKey($key);
        if ($row === null) {
            $this->settingDao->create(['key' => $key, 'value' => $encrypted]);
        } else {
            $this->settingDao->update($row->id, ['value' => $encrypted]);
        }
    }
}
