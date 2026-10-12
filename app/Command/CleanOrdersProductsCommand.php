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

use App\Service\Maintenance\DataCleanupService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * 清理订单和商品（上线前清测试数据）：`docker exec -it pb php bin/hyperf.php data:clean-orders-products`。
 *
 * 删什么、留什么见 DataCleanupService。不可恢复，执行前先备份数据库：
 * - 先列出每张表要删的行数；
 * - 要求输入当前数据库名确认（--force 跳过，给脚本用）；--dry-run 只看不删。
 */
#[Command]
class CleanOrdersProductsCommand extends HyperfCommand
{
    public function __construct(
        private readonly DataCleanupService $cleanupService,
        private readonly ConfigInterface $config,
    ) {
        parent::__construct('data:clean-orders-products');
    }

    public function configure(): void
    {
        $this->setDescription('清空订单和商品数据，商户余额归零、资金流水全删（不可恢复，先备份）')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '只列出要删的行数，不删')
            ->addOption('force', null, InputOption::VALUE_NONE, '不询问确认，直接删');

        parent::configure();
    }

    public function handle(): int
    {
        $database = (string) $this->config->get('databases.default.database', '');
        $counts = $this->cleanupService->counts();
        $this->warn("数据库：{$database}");
        $this->table(['表', '说明', '行数'], $this->rows($counts));
        $this->line('保留：供应商、商户和后台账号、管理员、号段、黑名单、通道维护、收款配置、付款账号、加款申请、系统配置、操作日志。');

        if ($this->input->getOption('dry-run')) {
            $this->info('--dry-run：只列出，没有删除。');

            return self::SUCCESS;
        }
        if (! $this->input->getOption('force')) {
            $answer = (string) $this->ask("以上数据会被永久删除、所有商户余额归零，确认请输入数据库名「{$database}」");
            if (trim($answer) !== $database) {
                $this->error('输入不一致，已取消，什么都没删。');

                return self::FAILURE;
            }
        }

        $deleted = $this->cleanupService->clean();
        $this->info('清理完成：');
        $this->table(['表', '说明', '删除 / 更新行数'], $this->rows($deleted));

        return self::SUCCESS;
    }

    /**
     * @param array<string, int> $counts
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function rows(array $counts): array
    {
        $labels = DataCleanupService::TABLES + ['merchants_with_balance' => '余额不为 0 的商户（归零）'];

        return array_map(static fn (string $table) => [$table, $labels[$table] ?? '', $counts[$table]], array_keys($counts));
    }
}
