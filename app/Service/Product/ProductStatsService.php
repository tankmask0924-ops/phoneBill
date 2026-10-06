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
use App\Dao\ProductDailyStatDao;
use App\Model\Order;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 平台商品每日统计：按商品 + 运营商汇总某天下单的订单，存进 product_daily_stats。
 *
 * - 订单按下单时间归到当天，不管几点完成；
 * - 成功率 = 成功 / (成功 + 失败)，还在处理中、异常的单单独计数，不进分母；
 * - 耗时 = 成功订单从下单受理到成功（finished_at - created_at）。
 *
 * 每天凌晨 3 点算前一天（订单最长 2 小时转异常，到时基本都有结果了），也可以用 stats:product-daily 重算。
 */
class ProductStatsService extends AbstractService
{
    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected ProductDailyStatDao $statDao;

    /**
     * 重算某一天，覆盖之前的结果。
     *
     * @return int 写了多少行（商品 × 运营商）
     */
    public function compute(string $date): int
    {
        $from = "{$date} 00:00:00";
        $to = "{$date} 23:59:59";
        $groups = $this->orderDao->newQuery()
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('product_id', 'operator')
            ->selectRaw("product_id, operator, count(*) as order_count, sum(status = 'success') as success_count, sum(status = 'failed') as failed_count")
            ->toBase()
            ->get();
        $durations = $this->durations($from, $to);

        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($groups as $group) {
            $success = (int) $group->success_count;
            $failed = (int) $group->failed_count;
            $sorted = $durations[$group->product_id . '|' . $group->operator] ?? [];
            $total = array_sum($sorted);
            $rows[] = [
                'stat_date' => $date,
                'product_id' => (int) $group->product_id,
                'operator' => $group->operator,
                'order_count' => (int) $group->order_count,
                'success_count' => $success,
                'failed_count' => $failed,
                'unfinished_count' => (int) $group->order_count - $success - $failed,
                'success_rate' => self::rate($success, $failed),
                'total_duration' => $total,
                'avg_duration' => $sorted === [] ? null : (int) round($total / count($sorted)),
                'p50_duration' => self::percentile($sorted, 0.5),
                'p90_duration' => self::percentile($sorted, 0.9),
                'computed_at' => $now,
            ];
        }
        $this->statDao->replaceDay($date, $rows);

        return count($rows);
    }

    /**
     * 成功率（百分比，两位小数），没有出结果的单时为 null。
     */
    public static function rate(int $success, int $failed): ?string
    {
        $finished = $success + $failed;

        return $finished === 0 ? null : bcdiv(bcmul((string) $success, '100', 4), (string) $finished, 2);
    }

    /**
     * 成功订单的耗时（秒），按商品 + 运营商分组、从小到大排好。
     *
     * @return array<string, list<int>> "商品ID|运营商" => 耗时列表
     */
    private function durations(string $from, string $to): array
    {
        $cursor = $this->orderDao->newQuery()
            ->whereBetween('created_at', [$from, $to])
            ->where('status', Order::STATUS_SUCCESS)
            ->whereNotNull('finished_at')
            ->selectRaw('product_id, operator, greatest(timestampdiff(second, created_at, finished_at), 0) as duration')
            ->orderBy('product_id')
            ->orderBy('operator')
            ->orderBy('duration')
            ->toBase()
            ->cursor();
        $result = [];
        foreach ($cursor as $row) {
            $result[$row->product_id . '|' . $row->operator][] = (int) $row->duration;
        }

        return $result;
    }

    /**
     * 最近秩法取百分位，$sorted 必须已从小到大排好。
     *
     * @param list<int> $sorted
     */
    private static function percentile(array $sorted, float $p): ?int
    {
        if ($sorted === []) {
            return null;
        }

        return $sorted[max(0, (int) ceil($p * count($sorted)) - 1)];
    }
}
