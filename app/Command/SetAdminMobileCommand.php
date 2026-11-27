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
use App\Support\Mobile;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;

/**
 * 设置管理员手机号：`docker exec pb php bin/hyperf.php admin:set-mobile --username=xxx --mobile=13800000000`。
 * 平台启用了短信后，登录验证码发到管理员的手机号，没有手机号登不上；一般在后台「管理员账号」里编辑即可，
 * 唯一的超级管理员没设置手机号、或者换了手机时用这个兜底。
 */
#[Command]
class SetAdminMobileCommand extends HyperfCommand
{
    public function __construct(private readonly AdminBootstrapService $adminBootstrapService)
    {
        parent::__construct('admin:set-mobile');
    }

    public function configure(): void
    {
        $this->setDescription('设置管理后台账号的手机号（登录验证码发到这里）')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, '登录用户名')
            ->addOption('mobile', null, InputOption::VALUE_REQUIRED, '11 位手机号');

        parent::configure();
    }

    public function handle(): int
    {
        try {
            $adminUser = $this->adminBootstrapService->setAdminMobile(
                (string) $this->input->getOption('username'),
                (string) $this->input->getOption('mobile'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("管理员 {$adminUser->username} 的手机号已设置为 " . Mobile::mask((string) $adminUser->mobile));

        return self::SUCCESS;
    }
}
