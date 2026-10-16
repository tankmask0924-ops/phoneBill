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

use App\Service\Product\ProvinceStatsService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * 重算省份统计：`docker exec pb php bin/hyperf.php stats:province-daily 2026-10-05 --days=7`。
 *
 * 平时由 StatsCrontab 每小时算当天；人工处理完异常订单、或者补历史数据时用它重算，结果覆盖原来的。
 */
#[Command]
class ProvinceDailyStatsCommand extends HyperfCommand
{
    public function __construct(private readonly ProvinceStatsService $statsService)
    {
        parent::__construct('stats:province-daily');
    }

    public function configure(): void
    {
        $this->setDescription('重算省份统计（按省份、运营商、供应商的成功率、金额、利润），覆盖已有结果');
        $this->addArgument('date', InputArgument::OPTIONAL, '要算的日期 Y-m-d，默认今天');
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, '从 date 往前一共算几天', '1');

        parent::configure();
    }

    public function handle(): int
    {
        $date = (string) ($this->input->getArgument('date') ?? date('Y-m-d'));
        $days = (int) $this->input->getOption('days');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || strtotime($date) === false || $days < 1 || $days > 366) {
            $this->error('日期格式是 Y-m-d，--days 在 1 到 366 之间');

            return self::FAILURE;
        }
        for ($i = $days - 1; $i >= 0; --$i) {
            $day = date('Y-m-d', strtotime("{$date} -{$i} day"));
            $this->line("{$day}：" . $this->statsService->compute($day) . ' 行');
        }

        return self::SUCCESS;
    }
}
