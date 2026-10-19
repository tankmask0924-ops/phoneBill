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
use App\Payment\AlipayClient;
use App\Service\AbstractService;
use App\Service\Payment\AlipayTransferService;
use App\Service\Setting\SettingService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Throwable;

/**
 * 系统设置 - 支付宝转账加款：开放平台应用（AppID、应用私钥、支付宝公钥，用来查账务明细）、收款账号、二维码有效期、单笔限额。
 * 应用私钥不回显，留空表示不改。
 */
class AlipayTransferSettingAdminService extends AbstractService
{
    use ValidatesAdminInput;

    private const MODULE = 'system';

    #[Inject]
    protected SettingService $settingService;

    #[Inject]
    protected AlipayTransferService $transferService;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @return array{enabled: bool, app_id: string, private_key_set: bool, alipay_public_key: string, account: string, account_name: string, expire_minutes: int, min_amount: string, max_amount: string}
     */
    public function get(): array
    {
        $config = $this->transferService->config();

        return [
            'enabled' => $config['enabled'] ?? false,
            'app_id' => $config['app_id'] ?? '',
            'private_key_set' => ($config['private_key'] ?? '') !== '',
            'alipay_public_key' => $config['alipay_public_key'] ?? '',
            'account' => $config['account'] ?? '',
            'account_name' => $config['account_name'] ?? '',
            'expire_minutes' => $config['expire_minutes'] ?? AlipayTransferService::DEFAULT_EXPIRE_MINUTES,
            'min_amount' => $config['min_amount'] ?? AlipayTransferService::DEFAULT_MIN_AMOUNT,
            'max_amount' => $config['max_amount'] ?? AlipayTransferService::DEFAULT_MAX_AMOUNT,
        ];
    }

    /**
     * @param array<string, mixed> $data enabled / app_id / private_key（留空不改）/ alipay_public_key / account / account_name / expire_minutes / min_amount / max_amount
     * @return array<string, mixed> 同 get()
     */
    public function save(AdminUser $operator, array $data, ?string $ip): array
    {
        $old = $this->transferService->config() ?? [];
        $privateKey = trim(is_string($data['private_key'] ?? null) ? $data['private_key'] : '');
        if ($privateKey === '') {
            $privateKey = (string) ($old['private_key'] ?? '');
        }
        if ($privateKey === '' || ! AlipayClient::isPrivateKey($privateKey)) {
            throw new HttpException(422, $privateKey === '' ? '请填写应用私钥' : '应用私钥格式不对，请粘贴支付宝密钥工具生成的应用私钥');
        }
        $appId = $this->requiredString($data['app_id'] ?? null, 'AppID', 32);
        if (preg_match('/^\d{16}$/', $appId) !== 1) {
            throw new HttpException(422, 'AppID 是 16 位数字');
        }
        $publicKey = trim(is_string($data['alipay_public_key'] ?? null) ? $data['alipay_public_key'] : '');
        if (! AlipayClient::isPublicKey($publicKey)) {
            throw new HttpException(422, '支付宝公钥格式不对（在开放平台「接口加签方式」里复制支付宝公钥，不是应用公钥）');
        }
        $account = $this->requiredString($data['account'] ?? null, '收款支付宝账号', 64);
        if (preg_match('/^1\d{10}$/', $account) !== 1 && filter_var($account, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(422, '收款支付宝账号填手机号或邮箱');
        }
        $expire = is_numeric($data['expire_minutes'] ?? null) ? (int) $data['expire_minutes'] : 0;
        if ($expire < 5 || $expire > 60) {
            throw new HttpException(422, '二维码有效期为 5～60 分钟');
        }
        $min = $this->money($data['min_amount'] ?? null, '单笔最低金额');
        $max = $this->money($data['max_amount'] ?? null, '单笔最高金额');
        if (bccomp($min, $max, 2) > 0) {
            throw new HttpException(422, '单笔最低金额不能大于最高金额');
        }
        $this->settingService->set(AlipayTransferService::SETTING_KEY, [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'app_id' => $appId,
            'private_key' => $privateKey,
            'alipay_public_key' => $publicKey,
            'account' => $account,
            'account_name' => $this->requiredString($data['account_name'] ?? null, '收款人姓名', 64),
            'expire_minutes' => $expire,
            'min_amount' => $min,
            'max_amount' => $max,
        ]);
        $after = $this->get();
        // 不记私钥
        $this->operationLogDao->record($operator->id, self::MODULE, 'update_alipay_transfer_setting', 'system_setting', null, null, array_diff_key($after, ['alipay_public_key' => true]), $ip);

        return $after;
    }

    /**
     * 用已保存的配置查最近一小时的账务明细，确认应用、密钥和接口权限都对。
     *
     * @return array{success: bool, message: string}
     */
    public function test(): array
    {
        $config = $this->transferService->config();
        if ($config === null) {
            return ['success' => false, 'message' => '还没有保存配置'];
        }
        try {
            $items = $this->transferService->client($config)->accountLogs(date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 1));
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'message' => '查询账务明细成功，最近一小时有 ' . count($items) . ' 条流水'];
    }
}
