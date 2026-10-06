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

use App\Service\Order\OrderProcessService;
use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;

/**
 * 把订单提交给下一个候选供应商（下单后、上一个供应商明确失败后）。
 */
class SubmitOrderJob extends Job
{
    // 不让队列自动重试：重复提交可能重复充值，漏掉的由定时任务补
    protected int $maxAttempts = 0;

    public function __construct(public int $orderId)
    {
    }

    public function handle(): void
    {
        ApplicationContext::getContainer()->get(OrderProcessService::class)->submit($this->orderId);
    }
}
