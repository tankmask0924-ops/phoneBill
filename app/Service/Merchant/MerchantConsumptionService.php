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

namespace App\Service\Merchant;

use App\Dao\MerchantDao;
use App\Dao\OrderDao;
use App\Model\Merchant;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 商户消费 = 成功订单的售价合计，按下单时间归属日期（和商户后台「消费金额」口径一致）。
 *
 * - 今日消费：实时查今天下单的成功订单；
 * - 累计消费：merchants.consumed_total 存 consumed_until 之前的合计，每天 3 点结转一次（settle()），
 *   显示时再加上 consumed_until 之后的实时数，所以即使哪天没结转也是准的；
 * - 结转后订单状态又被人工改了（成功改失败、失败改成功），已结转的部分不再跟着变。
 */
class MerchantConsumptionService extends AbstractService
{
    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected OrderDao $orderDao;

    /**
     * 把今天之前的成功订单结转进 consumed_total。
     *
     * @return int 结转了几个商户
     */
    public function settle(?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $settled = 0;
        foreach ($this->merchantDao->consumptionCursors() as $cursor) {
            $from = $cursor['consumed_until'];
            if ($from !== null && $from >= $today) {
                continue;
            }
            $amount = $this->orderDao->merchantSuccessAmount($cursor['id'], $from === null ? null : $from . ' 00:00:00', $today . ' 00:00:00');
            if ($this->merchantDao->settleConsumption($cursor['id'], $from, $today, $amount)) {
                ++$settled;
            }
        }

        return $settled;
    }

    /**
     * 一页商户的今日消费和累计消费（一次查询）。
     *
     * @param iterable<Merchant> $merchants
     * @return array<int, array{today_consumed: string, total_consumed: string}> 商户 id =>
     */
    public function totals(iterable $merchants): array
    {
        $today = date('Y-m-d');
        $cursors = [];
        $from = $today;
        foreach ($merchants as $merchant) {
            $cursor = $merchant->consumed_until?->toDateString() ?? '1970-01-01';
            $cursors[$merchant->id] = $cursor;
            $from = min($from, $cursor);
        }
        $result = [];
        foreach ($merchants as $merchant) {
            $result[$merchant->id] = ['today' => '0.00', 'tail' => '0.00', 'base' => bcadd((string) ($merchant->consumed_total ?? '0'), '0', 2)];
        }
        foreach ($this->orderDao->merchantDailySuccessAmounts(array_keys($cursors), $from . ' 00:00:00') as $row) {
            $r = &$result[$row['merchant_id']];
            if ($row['day'] >= $today) {
                $r['today'] = bcadd($r['today'], $row['amount'], 2);
            }
            if ($row['day'] >= $cursors[$row['merchant_id']]) {
                $r['tail'] = bcadd($r['tail'], $row['amount'], 2);
            }
            unset($r);
        }

        return array_map(static fn (array $r) => [
            'today_consumed' => $r['today'],
            'total_consumed' => bcadd($r['base'], $r['tail'], 2),
        ], $result);
    }
}
