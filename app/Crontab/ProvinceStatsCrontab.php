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

use App\Service\Product\ProvinceProductStatsService;
use App\Service\Product\ProvinceStatsService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 每小时整点重算当天的省份统计和省份商品统计。0～3 点顺带重算前一天：订单最长 2 小时转异常，到 3 点前一天的单基本都有结果了。
 */
#[Crontab(rule: '0 * * * *', name: 'ProvinceDailyStats', memo: '重算当天按省份、供应商 / 商品的订单统计', singleton: true, onOneServer: true, mutexExpires: 1800)]
class ProvinceStatsCrontab
{
    public function __invoke(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('crontab');
        $dates = [date('Y-m-d')];
        if ((int) date('G') <= 3) {
            array_unshift($dates, date('Y-m-d', strtotime('-1 day')));
        }
        foreach ($dates as $date) {
            try {
                $rows = $container->get(ProvinceStatsService::class)->compute($date);
                $logger->info("[ProvinceDailyStats] {$date}: {$rows} 行");
            } catch (Throwable $e) {
                $logger->error("[ProvinceDailyStats] {$date} 出错：" . $e->getMessage());
            }
            try {
                $rows = $container->get(ProvinceProductStatsService::class)->compute($date);
                $logger->info("[ProvinceProductDailyStats] {$date}: {$rows} 行");
            } catch (Throwable $e) {
                $logger->error("[ProvinceProductDailyStats] {$date} 出错：" . $e->getMessage());
            }
        }
    }
}
