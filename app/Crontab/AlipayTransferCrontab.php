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

use App\Service\Payment\AlipayTransferService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 支付宝转账加款：核对待支付单的到账、过期没到账的单、释放金额。每分钟一次，多台部署时只在一台上跑。
 */
#[Crontab(rule: '* * * * *', name: 'AlipayTransfer', memo: '支付宝转账加款核对到账、过期', singleton: true, onOneServer: true, mutexExpires: 120)]
class AlipayTransferCrontab
{
    public function __invoke(): void
    {
        $container = ApplicationContext::getContainer();
        $service = $container->get(AlipayTransferService::class);
        $logger = $container->get(LoggerFactory::class)->get('crontab');

        foreach (['reconcile', 'expire'] as $step) {
            try {
                $count = $service->{$step}();
                if ($count > 0) {
                    $logger->info("[AlipayTransfer] {$step}: {$count}");
                }
            } catch (Throwable $e) {
                $logger->error("[AlipayTransfer] {$step} 出错：" . $e->getMessage());
            }
        }
    }
}
