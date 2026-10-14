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

use App\Service\MerchantPortal\MerchantExportService;
use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;

/**
 * 生成商户后台的导出文件；出错时 MerchantExportService 把记录标为失败，不重试。
 */
class ExportMerchantDataJob extends Job
{
    protected int $maxAttempts = 0;

    public function __construct(public int $exportId)
    {
    }

    public function handle(): void
    {
        ApplicationContext::getContainer()->get(MerchantExportService::class)->generate($this->exportId);
    }
}
