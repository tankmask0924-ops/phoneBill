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

use App\Job\ConfirmSupplierCallbackJob;
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

    /**
     * @param null|int $preferredSupplierProductId 手动补单时指定先提交给哪个供应商商品
     */
    public function submit(int $orderId, int $delaySeconds = 0, ?int $preferredSupplierProductId = null): void
    {
        $this->driverFactory->get('default')->push(new SubmitOrderJob($orderId, $preferredSupplierProductId), $delaySeconds);
    }

    /**
     * @param int $round 通知轮次（orders.notify_round），轮次对不上的任务执行时直接作废
     */
    public function notify(int $orderId, int $attemptNo = 1, int $delaySeconds = 0, int $round = 0): void
    {
        $this->driverFactory->get('notify')->push(new NotifyMerchantJob($orderId, $attemptNo, $round), $delaySeconds);
    }

    /**
     * 回调后的确认查单没拿到明确结果，过一会重放这条回调重新确认。
     *
     * @param array<string, mixed> $query 原回调的查询参数
     * @param int $retry 第几次重新确认（从 1 开始）
     */
    public function confirmCallback(string $supplierCode, string $body, string $contentType, array $query, int $retry, int $delaySeconds): void
    {
        $this->driverFactory->get('default')->push(new ConfirmSupplierCallbackJob($supplierCode, $body, $contentType, $query, $retry), $delaySeconds);
    }
}
