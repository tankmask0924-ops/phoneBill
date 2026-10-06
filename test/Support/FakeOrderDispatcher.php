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

    public function submit(int $orderId, int $delaySeconds = 0): void
    {
        $this->submits[] = ['order_id' => $orderId, 'delay' => $delaySeconds];
    }

    public function notify(int $orderId, int $attemptNo = 1, int $delaySeconds = 0): void
    {
        $this->notifies[] = ['order_id' => $orderId, 'attempt_no' => $attemptNo, 'delay' => $delaySeconds];
    }
}
