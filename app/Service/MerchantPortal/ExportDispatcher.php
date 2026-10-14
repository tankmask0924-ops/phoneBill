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

namespace App\Service\MerchantPortal;

use App\Job\ExportMerchantDataJob;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Di\Annotation\Inject;

/**
 * 推导出任务（default 队列）。必须在导出记录提交之后调用；测试里替换掉，直接调 MerchantExportService::generate()。
 */
class ExportDispatcher
{
    #[Inject]
    protected DriverFactory $driverFactory;

    public function generate(int $exportId): void
    {
        $this->driverFactory->get('default')->push(new ExportMerchantDataJob($exportId));
    }
}
