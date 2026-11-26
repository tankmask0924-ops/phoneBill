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

use App\Service\Admin\AdminBootstrapService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;

/**
 * 解封管理员账号：`docker exec pb php bin/hyperf.php admin:unlock --username=xxx`。
 * 登录密码连续输错 5 次会自动封禁；一般在后台「管理员账号」里重新启用即可，唯一的超级管理员被封时用这个兜底。
 * 账号改回启用、连续输错次数清零，不改密码。
 */
#[Command]
class UnlockAdminCommand extends HyperfCommand
{
    public function __construct(private readonly AdminBootstrapService $adminBootstrapService)
    {
        parent::__construct('admin:unlock');
    }

    public function configure(): void
    {
        $this->setDescription('解封管理后台账号（改回启用、清零连续输错密码次数）')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, '登录用户名');

        parent::configure();
    }

    public function handle(): int
    {
        try {
            $adminUser = $this->adminBootstrapService->unlockAdmin((string) $this->input->getOption('username'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("管理员账号已解封：{$adminUser->username}");

        return self::SUCCESS;
    }
}
