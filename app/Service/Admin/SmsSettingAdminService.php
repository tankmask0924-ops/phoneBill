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
use App\Service\Sms\SmsService;
use App\Support\Mobile;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Throwable;

/**
 * 系统设置 - 短信配置：阿里云短信 AccessKey、签名、验证码模板、余额提醒模板。AccessKeySecret 不回显，留空表示不改。
 */
class SmsSettingAdminService extends AbstractService
{
    use ValidatesAdminInput;

    private const MODULE = 'system';

    #[Inject]
    protected SettingService $settingService;

    #[Inject]
    protected SmsService $smsService;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @return array{enabled: bool, access_key_id: string, access_key_secret_set: bool, sign_name: string, code_template: string, alert_template: string}
     */
    public function get(): array
    {
        $config = $this->settingService->get(SmsService::SETTING_KEY) ?? [];

        return [
            'enabled' => ($config['enabled'] ?? false) === true,
            'access_key_id' => (string) ($config['access_key_id'] ?? ''),
            'access_key_secret_set' => ($config['access_key_secret'] ?? '') !== '',
            'sign_name' => (string) ($config['sign_name'] ?? ''),
            'code_template' => (string) ($config['code_template'] ?? ''),
            'alert_template' => (string) ($config['alert_template'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $data enabled / access_key_id / access_key_secret（留空不改）/ sign_name / code_template / alert_template
     * @return array<string, mixed> 同 get()
     */
    public function save(AdminUser $operator, array $data, ?string $ip): array
    {
        $old = $this->settingService->get(SmsService::SETTING_KEY) ?? [];
        $secret = trim(is_string($data['access_key_secret'] ?? null) ? $data['access_key_secret'] : '');
        if ($secret === '') {
            $secret = (string) ($old['access_key_secret'] ?? '');
        }
        if ($secret === '') {
            throw new HttpException(422, '请填写 AccessKey Secret');
        }
        $config = [
            'enabled' => filter_var($data['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'access_key_id' => $this->requiredString($data['access_key_id'] ?? null, 'AccessKey ID', 128),
            'access_key_secret' => $secret,
            'sign_name' => $this->requiredString($data['sign_name'] ?? null, '短信签名', 32),
            'code_template' => $this->templateCode($data['code_template'] ?? null, '验证码模板'),
            'alert_template' => $this->templateCode($data['alert_template'] ?? null, '余额提醒模板'),
        ];
        $this->settingService->set(SmsService::SETTING_KEY, $config);
        $after = $this->get();
        // 不记密钥
        $this->operationLogDao->record($operator->id, self::MODULE, 'update_sms_setting', 'system_setting', null, null, $after, $ip);

        return $after;
    }

    /**
     * 用已保存的配置给指定手机号发一条验证码短信（随机码，不用于登录）。
     *
     * @return array{success: bool, message: string}
     */
    public function test(mixed $mobile): array
    {
        $mobile = Mobile::normalize($mobile) ?? throw new HttpException(422, '请填写正确的手机号');
        if (! $this->smsService->available()) {
            return ['success' => false, 'message' => '还没有启用或配置不完整'];
        }
        try {
            $this->smsService->sendCode($mobile, str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT));
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'message' => '已发送，请查看手机是否收到'];
    }

    private function templateCode(mixed $value, string $label): string
    {
        $code = $this->requiredString($value, $label, 32);
        if (preg_match('/^SMS_\d+$/', $code) !== 1) {
            throw new HttpException(422, "{$label}格式不对，如 SMS_123456789");
        }

        return $code;
    }
}
