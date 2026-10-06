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

namespace App\Process;

use Hyperf\AsyncQueue\Process\ConsumerProcess;
use Hyperf\Process\Annotation\Process;

/**
 * 消费 notify 队列（通知商户），和提交订单的队列分开，商户接口慢不影响充值。
 */
#[Process(name: 'async-queue-notify')]
class NotifyQueueConsumerProcess extends ConsumerProcess
{
    protected string $pool = 'notify';
}
