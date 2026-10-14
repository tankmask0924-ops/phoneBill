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

use App\Service\MerchantPortal\MerchantExportService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 每天删掉超过保留天数的商户导出记录和文件（见 MerchantExportService::KEEP_DAYS）。
 */
#[Crontab(rule: '30 3 * * *', name: 'MerchantExportCleanup', memo: '删除过期的商户导出文件', singleton: true, onOneServer: true, mutexExpires: 600)]
class MerchantExportCleanupCrontab
{
    public function __invoke(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('crontab');
        try {
            $deleted = $container->get(MerchantExportService::class)->cleanup();
            $logger->info("[MerchantExportCleanup] 删除 {$deleted} 条");
        } catch (Throwable $e) {
            $logger->error('[MerchantExportCleanup] 出错：' . $e->getMessage());
        }
    }
}
