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

namespace App\Service\Admin;

use App\Dao\MerchantDao;
use App\Dao\OrderDao;
use App\Service\AbstractService;
use Hyperf\Cache\Annotation\Cacheable;
use Hyperf\Di\Annotation\Inject;

/**
 * 管理后台首页汇总：全部订单的累计数据 + 今天的数据。销售额、利润只算成功的订单，按下单时间归属日期。
 */
class DashboardAdminService extends AbstractService
{
    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    /**
     * @return array{total: array<string, mixed>, today: array<string, mixed>}
     */
    public function summary(): array
    {
        $today = $this->orderDao->aggregate(date('Y-m-d 00:00:00'));

        return [
            'total' => $this->totals(),
            'today' => [
                'order_count' => $today['order_count'],
                'success_count' => $today['success_count'],
                'sales' => $today['sales'],
                'profit' => $today['profit'],
            ],
        ];
    }

    /**
     * 累计数据要扫全部订单，缓存 1 分钟。
     *
     * @return array{sales: string, merchant_count: int, order_count: int, profit: string}
     */
    #[Cacheable(prefix: 'admin_dashboard', value: 'totals', ttl: 60)]
    public function totals(): array
    {
        $all = $this->orderDao->aggregate();

        return [
            'sales' => $all['sales'],
            'merchant_count' => $this->merchantDao->newQuery()->count(),
            'order_count' => $all['order_count'],
            'profit' => $all['profit'],
        ];
    }
}
