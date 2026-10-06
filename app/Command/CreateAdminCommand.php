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
 * 开通第一个管理员账号：`docker exec pb php bin/hyperf.php admin:create --username=xxx --password=xxx`。
 * 管理员账号不能自助注册，部署后运维手动跑一次，之后在后台「管理员账号」页面建其他账号。
 *
 * 逻辑在 App\Service\Admin\AdminBootstrapService::createSuperAdmin()。可安全重复执行：
 * 重复用户名会以非 0 退出码失败，角色/权限/授权关系是 find-or-create。
 */
#[Command]
class CreateAdminCommand extends HyperfCommand
{
    public function __construct(private readonly AdminBootstrapService $adminBootstrapService)
    {
        parent::__construct('admin:create');
    }

    public function configure(): void
    {
        $this->setDescription('创建管理后台的超级管理员账号（幂等，可重复执行以创建更多账号）')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, '登录用户名')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, '登录密码')
            ->addOption('real-name', null, InputOption::VALUE_OPTIONAL, '显示名称，缺省时等于 username');

        parent::configure();
    }

    public function handle(): int
    {
        $username = (string) $this->input->getOption('username');
        $password = (string) $this->input->getOption('password');
        $realNameOption = $this->input->getOption('real-name');
        $realName = is_string($realNameOption) ? $realNameOption : null;

        try {
            $adminUser = $this->adminBootstrapService->createSuperAdmin($username, $password, $realName);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // 故意不打印密码——运维自己刚敲过一遍，没有必要在终端历史/日志里再留一份明文。
        $this->info("管理员账号创建成功：{$adminUser->username}");

        return self::SUCCESS;
    }
}
