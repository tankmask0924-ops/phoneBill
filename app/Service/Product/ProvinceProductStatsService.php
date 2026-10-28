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
use App\Dao\ProvinceProductDailyStatDao;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 省份商品统计：按「省份 + 运营商 + 平台商品」汇总某天下单的订单，存进 province_product_daily_stats，每天一份。
 *
 * 口径和 ProvinceStatsService 一样，只是把供应商换成了商品；两边由 ProvinceStatsCrontab 一起重算，同一天的合计对得上。
 */
class ProvinceProductStatsService extends AbstractService
{
    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected ProvinceProductDailyStatDao $statDao;

    /**
     * 重算某一天，覆盖之前的结果。
     *
     * @return int 写了多少行（省份 × 运营商 × 商品）
     */
    public function compute(string $date): int
    {
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($this->orderDao->provinceProductTotals("{$date} 00:00:00", "{$date} 23:59:59") as $group) {
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
