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

namespace App\Job;

use App\Service\Order\MerchantNotifyService;
use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;

/**
 * 把订单结果通知商户，失败后由 MerchantNotifyService 按间隔重新推入队列。
 */
class NotifyMerchantJob extends Job
{
    /** 通知轮次（见 orders.notify_round）；写成普通属性带默认值，队列里升级前的旧任务反序列化后为 0 */
    public int $round = 0;

    protected int $maxAttempts = 0;

    public function __construct(public int $orderId, public int $attemptNo, int $round = 0)
    {
        $this->round = $round;
    }

    public function handle(): void
    {
        ApplicationContext::getContainer()->get(MerchantNotifyService::class)->notify($this->orderId, $this->attemptNo, $this->round);
    }
}
