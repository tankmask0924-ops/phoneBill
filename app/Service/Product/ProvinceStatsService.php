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

namespace App\Service\Product;

use App\Dao\OrderDao;
use App\Dao\ProvinceDailyStatDao;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 省份统计：按「省份 + 运营商 + 最终供应商」汇总某天下单的订单，存进 province_daily_stats，每天一份。
 *
 * - 订单按下单时间归到当天，口径和 ProductStatsService 一样：成功率 = 成功 / (成功 + 失败)，没出结果的单单独计数；
 * - 换过供应商的订单只算在最终供应商上：成功的算成交的那家，失败、没出结果的算最后一次提交的那家；
 * - 成功金额是成功订单的售价（扣商户的钱）合计，利润 = 售价 - 成本。
 *
 * 每小时由 StatsCrontab 重算当天；凌晨几点再补算前一天，把晚上下的单的结果算进去。也可以用 stats:province-daily 重算。
 */
class ProvinceStatsService extends AbstractService
{
    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected ProvinceDailyStatDao $statDao;

    /**
     * 重算某一天，覆盖之前的结果。
     *
     * @return int 写了多少行（省份 × 运营商 × 供应商）
     */
    public function compute(string $date): int
    {
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($this->orderDao->provinceSupplierTotals("{$date} 00:00:00", "{$date} 23:59:59") as $group) {
            $rows[] = $group + [
                'stat_date' => $date,
                'unfinished_count' => $group['order_count'] - $group['success_count'] - $group['failed_count'],
                'computed_at' => $now,
            ];
        }
        $this->statDao->replaceDay($date, $rows);

        return count($rows);
    }
}
