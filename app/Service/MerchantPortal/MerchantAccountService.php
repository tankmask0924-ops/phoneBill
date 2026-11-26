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

namespace App\Service\MerchantPortal;

use App\Dao\MerchantDao;
use App\Model\Merchant;
use App\Model\MerchantUser;
use App\Model\SecurityLog;
use App\Security\Encrypter;
use App\Service\AbstractService;
use App\Service\Merchant\MerchantAccessService;
use App\Service\Security\SecurityLogService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * 商户后台 - 商户自己改 IP 白名单、重置签名密钥（规则和平台后台一样，见 MerchantAccessService）、设余额提醒阈值。
 *
 * - 都会影响开放接口能不能调通，所以要再输一次登录密码确认；
 * - 改完立即生效（Merchant 保存时清掉按 AppKey 的缓存）；新密钥只返回这一次，旧密钥立即失效；
 * - 操作记到 merchant 日志渠道，不记密钥本身；改白名单、重置密钥另记一条安全日志（SecurityLogService），平台后台「登录日志」里能查；
 * - 余额提醒阈值不影响接口，不用输密码（发送规则见 MerchantBalanceAlertService）。
 */
class MerchantAccountService extends AbstractService
{
    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantAccessService $accessService;

    #[Inject]
    protected Encrypter $encrypter;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    #[Inject]
    protected SecurityLogService $securityLogService;

    /**
     * @return array{ip_whitelist: list<string>}
     */
    public function updateIpWhitelist(MerchantUser $user, Merchant $merchant, string $password, mixed $ipWhitelist, ?string $ip): array
    {
        $this->assertPassword($user, $password);
        $value = $this->accessService->ipWhitelist($ipWhitelist);
        $before = $merchant->ipWhitelist();
        $this->merchantDao->update($merchant->id, ['ip_whitelist' => $value]);
        $after = $this->merchantDao->findOrFail($merchant->id)->ipWhitelist();
        $this->logger()->info('商户修改 IP 白名单', ['merchant_id' => $merchant->id, 'user' => $user->username, 'ip' => $ip, 'before' => $before, 'after' => $after]);
        $this->securityLogService->record(SecurityLog::PORTAL_MERCHANT, SecurityLog::EVENT_UPDATE_IP_WHITELIST, $user->id, $user->username, $merchant->id, ['before' => $before, 'after' => $after]);

        return ['ip_whitelist' => $after];
    }

    /**
     * @return array{app_key: string, app_secret: string}
     */
    public function resetSecret(MerchantUser $user, Merchant $merchant, string $password, ?string $ip): array
    {
        $this->assertPassword($user, $password);
        $secret = $this->accessService->newSecret();
        $this->merchantDao->update($merchant->id, ['app_secret' => $this->encrypter->encrypt($secret)]);
        $this->logger()->info('商户重置签名密钥', ['merchant_id' => $merchant->id, 'user' => $user->username, 'ip' => $ip]);
        $this->securityLogService->record(SecurityLog::PORTAL_MERCHANT, SecurityLog::EVENT_RESET_SECRET, $user->id, $user->username, $merchant->id);

        return ['app_key' => $merchant->app_key, 'app_secret' => $secret];
    }

    /**
     * 设余额提醒阈值，空值表示关闭提醒。改阈值不影响当天已发的条数，每天上限照旧。
     *
     * @return array{balance_alert_threshold: null|string}
     */
    public function updateBalanceAlert(MerchantUser $user, Merchant $merchant, mixed $threshold, ?string $ip): array
    {
        $value = null;
        if ($threshold !== null && $threshold !== '') {
            $text = is_string($threshold) || is_int($threshold) || is_float($threshold) ? trim((string) $threshold) : '';
            if (preg_match('/^\d{1,8}(\.\d{1,2})?$/', $text) !== 1 || bccomp($text, '0', 2) <= 0) {
                throw new HttpException(422, '提醒阈值要大于 0，最多两位小数');
            }
            $value = bcadd($text, '0', 2);
        }
        $before = $merchant->balance_alert_threshold;
        $this->merchantDao->update($merchant->id, ['balance_alert_threshold' => $value]);
        $this->logger()->info('商户修改余额提醒阈值', ['merchant_id' => $merchant->id, 'user' => $user->username, 'ip' => $ip, 'before' => $before, 'after' => $value]);

        return ['balance_alert_threshold' => $value];
    }

    private function assertPassword(MerchantUser $user, string $password): void
    {
        if ($password === '' || ! password_verify($password, $user->password)) {
            throw new HttpException(422, '登录密码不正确');
        }
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('merchant');
    }
}
