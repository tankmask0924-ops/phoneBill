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

namespace App\Service\Storage;

use App\Network\HttpClient;
use App\Service\AbstractService;
use App\Service\Setting\SettingService;
use App\Storage\CosClient;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpMessage\Upload\UploadedFile;

/**
 * 图片存腾讯云 COS（平台后台「存储配置」里填参数）。只存 key，查看时临时生成签名链接，存储桶保持私有。
 */
class ObjectStorageService extends AbstractService
{
    /** 设置项的 key */
    public const SETTING_KEY = 'storage.cos';

    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    /** 允许的图片类型 => 扩展名 */
    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** 临时链接有效期（秒） */
    private const URL_TTL = 600;

    #[Inject]
    protected SettingService $settingService;

    #[Inject]
    protected HttpClient $http;

    /**
     * @return null|array{secret_id: string, secret_key: string, region: string, bucket: string, path_prefix: string}
     */
    public function config(): ?array
    {
        $config = $this->settingService->get(self::SETTING_KEY);
        foreach (['secret_id', 'secret_key', 'region', 'bucket'] as $field) {
            if (! is_string($config[$field] ?? null) || $config[$field] === '') {
                return null;
            }
        }

        return [
            'secret_id' => $config['secret_id'],
            'secret_key' => $config['secret_key'],
            'region' => $config['region'],
            'bucket' => $config['bucket'],
            'path_prefix' => is_string($config['path_prefix'] ?? null) ? $config['path_prefix'] : '',
        ];
    }

    /**
     * @param null|array{secret_id: string, secret_key: string, region: string, bucket: string, path_prefix: string} $config 不传就用已保存的
     */
    public function client(?array $config = null): CosClient
    {
        $config ??= $this->config();
        if ($config === null) {
            throw new HttpException(422, '还没有配置图片存储（腾讯云 COS），请联系平台');
        }

        return new CosClient($this->http, $config['secret_id'], $config['secret_key'], $config['bucket'], $config['region']);
    }

    /**
     * 校验并上传一张图片，返回对象 key：{前缀/}{目录}/{日期}/{随机}.{扩展名}。
     */
    public function uploadImage(string $dir, mixed $file): string
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw new HttpException(422, '请选择要上传的图片');
        }
        if ($file->getSize() > self::MAX_IMAGE_BYTES) {
            throw new HttpException(422, '图片不能超过 5MB');
        }
        $path = $file->getRealPath();
        $info = $path === false ? false : @getimagesize($path);
        $ext = $info === false ? null : (self::IMAGE_TYPES[$info['mime']] ?? null);
        if ($ext === null) {
            throw new HttpException(422, '只支持 JPG、PNG、WebP 图片');
        }
        $config = $this->config();
        $key = $this->key($config['path_prefix'] ?? '', $dir . '/' . date('Ymd') . '/' . bin2hex(random_bytes(12)) . '.' . $ext);
        $this->client($config)->put($key, (string) file_get_contents($path), $info['mime']);

        return $key;
    }

    /**
     * 这个 key 是否在 $dir 目录下（防止引用别人的图片）。
     */
    public function isUnder(string $key, string $dir): bool
    {
        return str_starts_with($key, $this->key($this->config()['path_prefix'] ?? '', rtrim($dir, '/') . '/')) && ! str_contains($key, '..');
    }

    public function url(string $key): string
    {
        return $this->client()->presignedUrl($key, self::URL_TTL);
    }

    private function key(string $prefix, string $path): string
    {
        $prefix = trim($prefix, '/');

        return $prefix === '' ? $path : $prefix . '/' . $path;
    }
}
