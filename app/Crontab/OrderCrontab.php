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

use App\Service\Order\OrderProcessService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 订单兜底：主动查单、超时转异常、补推丢了的提交任务。每分钟一次，多台部署时只在一台上跑。
 */
#[Crontab(rule: '* * * * *', name: 'OrderMaintenance', memo: '订单查单、转异常、补推提交', singleton: true, onOneServer: true, mutexExpires: 300)]
class OrderCrontab
{
    public function __invoke(): void
    {
        $container = ApplicationContext::getContainer();
        $service = $container->get(OrderProcessService::class);
        $logger = $container->get(LoggerFactory::class)->get('crontab');

        foreach (['queryDue', 'markAbnormal', 'resubmitStuck'] as $step) {
            try {
                $count = $service->{$step}();
                if ($count > 0) {
                    $logger->info("[OrderMaintenance] {$step}: {$count}");
                }
            } catch (Throwable $e) {
                $logger->error("[OrderMaintenance] {$step} 出错：" . $e->getMessage());
            }
        }
    }
}
