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

use App\Service\Merchant\MerchantConsumptionService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 每天 3 点 10 分把今天之前的成功订单结转进商户累计消费。和商品统计一样等到 3 点后，前一天的单基本都有结果了。
 */
#[Crontab(rule: '10 3 * * *', name: 'MerchantConsumption', memo: '结转商户累计消费', singleton: true, onOneServer: true, mutexExpires: 3600)]
class MerchantConsumptionCrontab
{
    public function __invoke(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('crontab');
        try {
            $count = $container->get(MerchantConsumptionService::class)->settle();
            $logger->info("[MerchantConsumption] 结转了 {$count} 个商户");
        } catch (Throwable $e) {
            $logger->error('[MerchantConsumption] 出错：' . $e->getMessage());
        }
    }
}
