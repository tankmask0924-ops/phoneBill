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
    /** 手动补单时指定的供应商商品；普通属性带默认值，升级前的旧任务反序列化后为 null */
    public ?int $preferredSupplierProductId = null;

    // 不让队列自动重试：重复提交可能重复充值，漏掉的由定时任务补
    protected int $maxAttempts = 0;

    public function __construct(public int $orderId, ?int $preferredSupplierProductId = null)
    {
        $this->preferredSupplierProductId = $preferredSupplierProductId;
    }

    public function handle(): void
    {
        ApplicationContext::getContainer()->get(OrderProcessService::class)->submit($this->orderId, $this->preferredSupplierProductId);
    }
}
