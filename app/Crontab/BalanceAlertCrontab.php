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

namespace App\Crontab;

use App\Service\Merchant\MerchantBalanceAlertService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 每 5 分钟检查一次商户余额：跌破商户设的阈值就发短信提醒充值，充值回到阈值以上后再跌破会再提醒（规则见 MerchantBalanceAlertService）。
 */
#[Crontab(rule: '*/5 * * * *', name: 'MerchantBalanceAlert', memo: '商户余额跌破阈值时短信提醒', singleton: true, onOneServer: true, mutexExpires: 600)]
class BalanceAlertCrontab
{
    public function __invoke(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('crontab');
        try {
            $count = $container->get(MerchantBalanceAlertService::class)->run();
            if ($count > 0) {
                $logger->info("[MerchantBalanceAlert] 提醒了 {$count} 个商户");
            }
        } catch (Throwable $e) {
            $logger->error('[MerchantBalanceAlert] 出错：' . $e->getMessage());
        }
    }
}
