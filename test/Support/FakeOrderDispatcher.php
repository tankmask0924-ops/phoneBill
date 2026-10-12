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

namespace HyperfTest\Support;

use App\Service\Order\OrderDispatcher;

/**
 * 不真的推队列，只记下推了什么，测试里自己调处理逻辑。
 */
class FakeOrderDispatcher extends OrderDispatcher
{
    /** @var list<array{order_id: int, delay: int}> */
    public array $submits = [];

    /** @var list<array{order_id: int, attempt_no: int, delay: int}> */
    public array $notifies = [];

    /**
     * 指定了补单的供应商商品时才多记一个 preferred，其余和以前一样。
     */
    public function submit(int $orderId, int $delaySeconds = 0, ?int $preferredSupplierProductId = null): void
    {
        $this->submits[] = ['order_id' => $orderId, 'delay' => $delaySeconds] + ($preferredSupplierProductId === null ? [] : ['preferred' => $preferredSupplierProductId]);
    }

    /**
     * 通知轮次不是 0（手动回调过）时才多记一个 round。
     */
    public function notify(int $orderId, int $attemptNo = 1, int $delaySeconds = 0, int $round = 0): void
    {
        $this->notifies[] = ['order_id' => $orderId, 'attempt_no' => $attemptNo, 'delay' => $delaySeconds] + ($round === 0 ? [] : ['round' => $round]);
    }
}
