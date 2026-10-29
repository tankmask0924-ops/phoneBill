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

use App\Service\Order\OrderCityBackfillService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * 给还没记市的订单补上市：`docker exec pb php bin/hyperf.php orders:backfill-city`。
 *
 * 加了 orders.city 之后跑一次，补完再用 stats:province-daily 重算历史的按市统计。可以重复跑，只处理还没记市的。
 */
#[Command]
class BackfillOrderCityCommand extends HyperfCommand
{
    public function __construct(private readonly OrderCityBackfillService $backfillService)
    {
        parent::__construct('orders:backfill-city');
    }

    public function configure(): void
    {
        $this->setDescription('按手机号查号段库，给还没记市的订单补上市（城市统一写法）');
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, '每批处理多少单', '2000');

        parent::configure();
    }

    public function handle(): int
    {
        $batch = (int) $this->input->getOption('batch');
        if ($batch < 1 || $batch > 10000) {
            $this->error('--batch 在 1 到 10000 之间');

            return self::FAILURE;
        }
        $result = $this->backfillService->backfill($batch, fn (int $lastId, int $filled) => $this->line("处理到订单 #{$lastId}，已补 {$filled} 单"));
        $this->info("补上 {$result['filled']} 单，号段库查不到的 {$result['missing']} 单");

        return self::SUCCESS;
    }
}
