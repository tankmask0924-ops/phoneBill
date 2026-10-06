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

namespace HyperfTest\Cases\Command;

use App\Command\CreateAdminCommand;
use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use Hyperf\Testing\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * admin:create 的胶水层冒烟测试：用 Symfony Console 的 CommandTester 真的跑一遍 execute()，
 * 确认命令能从容器解析、选项透传给 Service、校验异常转成非 0 退出码、成功时不打印密码。
 * 业务分支（幂等、重复用户名、密码长度等）见 test/Cases/Service/Admin/AdminBootstrapServiceTest.php。
 *
 * @internal
 * @coversNothing
 */
class CreateAdminCommandTest extends TestCase
{
    private const ROLE_NAME = 'super_admin';

    private const PERMISSION_CODE = 'admin_user.view';

    private array $adminUserIds = [];

    private bool $roleOwnedByThisTest = false;

    private bool $permissionOwnedByThisTest = false;

    protected function tearDown(): void
    {
        foreach ($this->adminUserIds as $id) {
            AdminUser::destroy($id);
        }

        if ($this->roleOwnedByThisTest) {
            $role = AdminRole::where('name', self::ROLE_NAME)->first();
            if ($role) {
                AdminRolePermission::where('role_id', $role->id)->delete();
                AdminRole::destroy($role->id);
            }
        }

        if ($this->permissionOwnedByThisTest) {
            $permission = AdminPermission::where('code', self::PERMISSION_CODE)->first();
            if ($permission) {
                AdminRolePermission::where('permission_id', $permission->id)->delete();
                AdminPermission::destroy($permission->id);
            }
        }

        $this->adminUserIds = [];
        $this->roleOwnedByThisTest = false;
        $this->permissionOwnedByThisTest = false;

        parent::tearDown();
    }

    public function testCommandIsResolvableWithExpectedNameAndOptions()
    {
        $command = $this->getContainer()->get(CreateAdminCommand::class);

        $this->assertSame('admin:create', $command->getName());
        $this->assertTrue($command->getDefinition()->hasOption('username'));
        $this->assertTrue($command->getDefinition()->hasOption('password'));
        $this->assertTrue($command->getDefinition()->hasOption('real-name'));
    }

    public function testExecuteWithValidInputCreatesAdminAndPrintsUsernameButNotPassword()
    {
        $this->rememberOwnershipBeforeCall();
        $username = $this->uniqueUsername();
        $password = 'a-strong-password';

        $tester = new CommandTester($this->getContainer()->get(CreateAdminCommand::class));
        $exitCode = $tester->execute([
            '--username' => $username,
            '--password' => $password,
        ]);

        $admin = AdminUser::where('username', $username)->first();
        $this->assertNotNull($admin);
        $this->adminUserIds[] = $admin->id;

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString($username, $display);
        $this->assertStringNotContainsString($password, $display);
    }

    public function testExecuteWithTooShortPasswordFailsWithNonZeroExitCodeAndNoRowCreated()
    {
        $username = $this->uniqueUsername();

        $tester = new CommandTester($this->getContainer()->get(CreateAdminCommand::class));
        $exitCode = $tester->execute([
            '--username' => $username,
            '--password' => 'short',
        ]);

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(0, AdminUser::where('username', $username)->count());
    }

    private function uniqueUsername(): string
    {
        return 'admin_' . uniqid('', true);
    }

    private function rememberOwnershipBeforeCall(): void
    {
        $this->roleOwnedByThisTest = ! AdminRole::where('name', self::ROLE_NAME)->exists();
        $this->permissionOwnedByThisTest = ! AdminPermission::where('code', self::PERMISSION_CODE)->exists();
    }
}
