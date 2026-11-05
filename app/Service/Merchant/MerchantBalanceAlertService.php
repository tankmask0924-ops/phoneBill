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

namespace App\Service\Merchant;

use App\Dao\MerchantDao;
use App\Dao\MerchantUserDao;
use App\Service\AbstractService;
use App\Service\Sms\SmsService;
use App\Support\Mobile;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 余额提醒：商户在商户后台「账户」里设阈值，余额跌破阈值时给这个商户所有启用中、绑定了手机的账号发短信。
 *
 * - 跌破一次只提醒一次：发完暂停（balance_alert_armed），余额回到阈值以上（比如充值了）再重新开启，下次跌破再提醒；
 *   一直在阈值以下就不再发；
 * - 每个商户每天最多 DAILY_LIMIT 条，防止余额在阈值附近来回时发太多；先占住再发，多台部署也不会重复；
 * - 一条都没发出去（短信接口出错）时还原，下一轮再试；没有绑定手机的账号就不发、也不占；
 * - 平台没启用短信时什么都不做。由 BalanceAlertCrontab 每 5 分钟跑一次，所以充值后 5 分钟内又跌破的，看不到「回到阈值以上」，不会再提醒。
 */
class MerchantBalanceAlertService extends AbstractService
{
    /** 每个商户每天最多发几条 */
    public const DAILY_LIMIT = 3;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantUserDao $merchantUserDao;

    #[Inject]
    protected SmsService $smsService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @return int 发了提醒的商户数
     */
    public function run(): int
    {
        if (! $this->smsService->available()) {
            return 0;
        }
        $this->merchantDao->rearmBalanceAlerts();
        $today = date('Y-m-d');
        $logger = $this->loggerFactory->get('sms');
        $alerted = 0;
        foreach ($this->merchantDao->dueForBalanceAlert($today, self::DAILY_LIMIT) as $merchant) {
            $mobiles = $this->merchantUserDao->activeMobilesOfMerchant($merchant->id);
            if ($mobiles === [] || ! $this->merchantDao->claimBalanceAlert($merchant->id, $today, self::DAILY_LIMIT)) {
                continue;
            }
            $sent = 0;
            foreach ($mobiles as $mobile) {
                try {
                    $this->smsService->sendBalanceAlert($mobile, $merchant->name, (string) $merchant->balance, (string) $merchant->balance_alert_threshold);
                    ++$sent;
                } catch (Throwable $e) {
                    $logger->error('发送余额提醒失败', ['merchant_id' => $merchant->id, 'mobile' => Mobile::mask($mobile), 'error' => $e->getMessage()]);
                }
            }
            if ($sent === 0) {
                $this->merchantDao->releaseBalanceAlert($merchant->id, $today, $merchant->balance_alert_date?->toDateString(), (int) $merchant->balance_alert_count);
                continue;
            }
            $logger->info('已发送余额提醒', ['merchant_id' => $merchant->id, 'balance' => $merchant->balance, 'threshold' => $merchant->balance_alert_threshold, 'sent' => $sent]);
            ++$alerted;
        }

        return $alerted;
    }
}
