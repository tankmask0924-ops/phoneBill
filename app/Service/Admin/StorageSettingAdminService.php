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
use App\Service\Storage\ObjectStorageService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Throwable;

/**
 * 系统设置 - 存储配置：腾讯云 COS 参数（商户加款凭证图片存这里）。SecretKey 不回显，留空表示不改。
 */
class StorageSettingAdminService extends AbstractService
{
    use ValidatesAdminInput;

    private const MODULE = 'system';

    #[Inject]
    protected SettingService $settingService;

    #[Inject]
    protected ObjectStorageService $storage;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @return array{secret_id: string, secret_key_set: bool, region: string, bucket: string, path_prefix: string}
     */
    public function get(): array
    {
        $config = $this->settingService->get(ObjectStorageService::SETTING_KEY) ?? [];

        return [
            'secret_id' => (string) ($config['secret_id'] ?? ''),
            'secret_key_set' => ($config['secret_key'] ?? '') !== '',
            'region' => (string) ($config['region'] ?? ''),
            'bucket' => (string) ($config['bucket'] ?? ''),
            'path_prefix' => (string) ($config['path_prefix'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $data secret_id / secret_key（留空不改）/ region / bucket / path_prefix
     * @return array<string, mixed> 同 get()
     */
    public function save(AdminUser $operator, array $data, ?string $ip): array
    {
        $old = $this->settingService->get(ObjectStorageService::SETTING_KEY) ?? [];
        $secretKey = trim(is_string($data['secret_key'] ?? null) ? $data['secret_key'] : '');
        if ($secretKey === '') {
            $secretKey = (string) ($old['secret_key'] ?? '');
        }
        if ($secretKey === '') {
            throw new HttpException(422, '请填写 SecretKey');
        }
        $region = $this->requiredString($data['region'] ?? null, '地域', 32);
        if (preg_match('/^[a-z0-9-]+$/', $region) !== 1) {
            throw new HttpException(422, '地域格式不对，如 ap-guangzhou');
        }
        $bucket = $this->requiredString($data['bucket'] ?? null, '存储桶名称', 64);
        if (preg_match('/^[a-z0-9-]+-\d+$/', $bucket) !== 1) {
            throw new HttpException(422, '存储桶名称要带 APPID，如 examplebucket-1250000000');
        }
        $prefix = trim(is_string($data['path_prefix'] ?? null) ? $data['path_prefix'] : '', ' /');
        if ($prefix !== '' && preg_match('#^[A-Za-z0-9_\-/]{1,64}$#', $prefix) !== 1) {
            throw new HttpException(422, '路径前缀只能是字母、数字、_、-、/');
        }
        $config = [
            'secret_id' => $this->requiredString($data['secret_id'] ?? null, 'SecretId', 128),
            'secret_key' => $secretKey,
            'region' => $region,
            'bucket' => $bucket,
            'path_prefix' => $prefix,
        ];
        $this->settingService->set(ObjectStorageService::SETTING_KEY, $config);
        $after = $this->get();
        // 不记密钥
        $this->operationLogDao->record($operator->id, self::MODULE, 'update_storage_setting', 'system_setting', null, null, $after, $ip);

        return $after;
    }

    /**
     * 用已保存的配置上传、删除一个测试文件，确认参数和权限都对。
     *
     * @return array{success: bool, message: string}
     */
    public function test(): array
    {
        $config = $this->storage->config();
        if ($config === null) {
            return ['success' => false, 'message' => '还没有保存配置'];
        }
        $key = trim($config['path_prefix'] . '/connection-test/' . date('YmdHis') . '.txt', '/');
        try {
            $client = $this->storage->client($config);
            $client->put($key, 'phonebill storage test', 'text/plain');
            $client->delete($key);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'message' => '上传、删除测试文件都成功'];
    }
}
