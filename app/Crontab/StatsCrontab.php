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

use App\Service\Product\ProductStatsService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 每天 3 点汇总前一天的商品统计。订单最长 2 小时转异常，到 3 点前一天的单基本都有结果了。
 */
#[Crontab(rule: '0 3 * * *', name: 'ProductDailyStats', memo: '汇总前一天的商品成功率和耗时', singleton: true, onOneServer: true, mutexExpires: 3600)]
class StatsCrontab
{
    public function __invoke(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('crontab');
        $date = date('Y-m-d', strtotime('-1 day'));
        try {
            $rows = $container->get(ProductStatsService::class)->compute($date);
            $logger->info("[ProductDailyStats] {$date}: {$rows} 行");
        } catch (Throwable $e) {
            $logger->error("[ProductDailyStats] {$date} 出错：" . $e->getMessage());
        }
    }
}
