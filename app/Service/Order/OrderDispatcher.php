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

namespace App\Service\Order;

use App\Job\NotifyMerchantJob;
use App\Job\SubmitOrderJob;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Di\Annotation\Inject;

/**
 * 订单相关的异步任务都从这里推：提交订单走 default 队列，通知商户走 notify 队列（见 async_queue.php）。
 * 必须在数据库事务提交之后再调用，否则任务可能读到还没提交的数据。
 * 测试里替换成 mock，直接调处理逻辑。
 */
class OrderDispatcher
{
    #[Inject]
    protected DriverFactory $driverFactory;

    public function submit(int $orderId, int $delaySeconds = 0): void
    {
        $this->driverFactory->get('default')->push(new SubmitOrderJob($orderId), $delaySeconds);
    }

    public function notify(int $orderId, int $attemptNo = 1, int $delaySeconds = 0): void
    {
        $this->driverFactory->get('notify')->push(new NotifyMerchantJob($orderId, $attemptNo), $delaySeconds);
    }
}
