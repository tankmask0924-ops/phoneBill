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

namespace App\Command;

use App\Service\Mobile\SegmentImportService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * 导入号段库：`docker exec pb php bin/hyperf.php segment:import docs/recharge_phone_prefix_info.sql`。
 *
 * 文件是 Navicat 导出的 recharge_phone_prefix_info 表；按号段覆盖写入，可以重复执行。
 * 先加 --dry-run 看统计，确认再正式导入。转换规则见 SegmentImportService。
 */
#[Command]
class ImportSegmentsCommand extends HyperfCommand
{
    public function __construct(private readonly SegmentImportService $importService)
    {
        parent::__construct('segment:import');
    }

    public function configure(): void
    {
        $this->setDescription('从号段库 SQL 文件导入 mobile_segments（按号段覆盖，可重复执行）');
        $this->addArgument('file', InputArgument::REQUIRED, 'SQL 文件路径（容器里的路径，项目目录是 /opt/www）');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, '只统计不写库');

        parent::configure();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->input->getOption('dry-run');
        $started = microtime(true);
        try {
            $stats = $this->importService->import((string) $this->input->getArgument('file'), $dryRun, function (int $done) {
                if ($done % 50000 < 2000) {
                    $this->line("  已处理 {$done} 行");
                }
            });
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dryRun ? '【试运行，未写库】' : '导入完成') . sprintf('，用时 %.1f 秒', microtime(true) - $started));
        $this->line("读到 {$stats['rows']} 行，导入 {$stats['imported']} 行，其中虚拟运营商 {$stats['virtual']} 行、未校验 {$stats['unverified']} 行");
        $this->line('按运营商：' . json_encode($stats['by_operator'], JSON_UNESCAPED_UNICODE));
        if ($stats['deleted'] > 0) {
            $this->line("已删除标记的跳过 {$stats['deleted']} 行");
        }
        foreach ($stats['skipped'] as $reason => $count) {
            $this->warn("跳过 {$count} 行：{$reason}");
        }
        if ($stats['unparsed'] > 0) {
            $this->warn("格式认不出 {$stats['unparsed']} 行，例如：");
            foreach ($stats['unparsed_samples'] as $sample) {
                $this->line('  ' . $sample);
            }
        }

        return self::SUCCESS;
    }
}
