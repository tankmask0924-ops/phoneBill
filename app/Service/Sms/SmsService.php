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

namespace App\Service\Sms;

use App\Network\HttpClient;
use App\Service\AbstractService;
use App\Service\Setting\SettingService;
use App\Sms\AliyunSmsClient;
use Hyperf\Di\Annotation\Inject;
use RuntimeException;

/**
 * 发短信（阿里云短信，平台后台「系统配置 - 短信配置」里填参数）。只有两个模板：.
 *
 * - 验证码模板：登录验证、绑定 / 修改手机共用，模板变量 ${code}；
 * - 余额提醒模板：模板变量 ${name}（商户名称）、${balance}（当前余额）、${threshold}（提醒阈值）。
 *
 * 没启用或参数没填全时 available() 为 false：登录不要求验证码、不能绑定手机、不发余额提醒。
 */
class SmsService extends AbstractService
{
    /** 设置项的 key */
    public const SETTING_KEY = 'sms.aliyun';

    #[Inject]
    protected SettingService $settingService;

    #[Inject]
    protected HttpClient $http;

    /**
     * 启用了并且参数齐全才返回，否则 null。
     *
     * @return null|array{access_key_id: string, access_key_secret: string, sign_name: string, code_template: string, alert_template: string}
     */
    public function config(): ?array
    {
        $config = $this->settingService->get(self::SETTING_KEY);
        if (! is_array($config) || ($config['enabled'] ?? false) !== true) {
            return null;
        }
        $result = [];
        foreach (['access_key_id', 'access_key_secret', 'sign_name', 'code_template', 'alert_template'] as $field) {
            if (! is_string($config[$field] ?? null) || $config[$field] === '') {
                return null;
            }
            $result[$field] = $config[$field];
        }

        return $result;
    }

    public function available(): bool
    {
        return $this->config() !== null;
    }

    /**
     * @throws RuntimeException 没配置或发送失败
     */
    public function sendCode(string $mobile, string $code): void
    {
        $config = $this->requireConfig();
        $this->client($config)->send($mobile, $config['sign_name'], $config['code_template'], ['code' => $code]);
    }

    /**
     * @throws RuntimeException 没配置或发送失败
     */
    public function sendBalanceAlert(string $mobile, string $merchantName, string $balance, string $threshold): void
    {
        $config = $this->requireConfig();
        $this->client($config)->send($mobile, $config['sign_name'], $config['alert_template'], [
            'name' => $merchantName,
            'balance' => $balance,
            'threshold' => $threshold,
        ]);
    }

    /**
     * @return array{access_key_id: string, access_key_secret: string, sign_name: string, code_template: string, alert_template: string}
     */
    private function requireConfig(): array
    {
        return $this->config() ?? throw new RuntimeException('短信服务没有启用或没有配置完整');
    }

    /**
     * @param array{access_key_id: string, access_key_secret: string} $config
     */
    private function client(array $config): AliyunSmsClient
    {
        return new AliyunSmsClient($this->http, $config['access_key_id'], $config['access_key_secret']);
    }
}
