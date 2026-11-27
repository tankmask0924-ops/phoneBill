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

use App\Service\Order\SupplierCallbackService;
use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;

/**
 * 回调后的确认查单没拿到明确结果：过一会把原回调重放一遍，重新查单确认（见 SupplierCallbackService::RETRY_DELAYS）。
 * 只放标量：供应商编码、原回调的报文、Content-Type、查询参数。
 */
class ConfirmSupplierCallbackJob extends Job
{
    // 失败不让队列自动重试，重试次数由 SupplierCallbackService 控制
    protected int $maxAttempts = 0;

    /**
     * @param array<string, mixed> $query
     */
    public function __construct(
        public string $supplierCode,
        public string $body,
        public string $contentType,
        public array $query,
        public int $retry,
    ) {
    }

    public function handle(): void
    {
        ApplicationContext::getContainer()->get(SupplierCallbackService::class)->replay($this->supplierCode, $this->body, $this->contentType, $this->query, $this->retry);
    }
}
