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

namespace HyperfTest\Cases\Service\Admin;

use App\Dao\AdminRolePermissionDao;
use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use App\Service\Admin\AdminBootstrapService;
use Hyperf\Testing\TestCase;
use InvalidArgumentException;

/**
 * App\Service\Admin\AdminBootstrapService::createSuperAdmin() / syncSuperAdminPermissions()。
 * 命令行胶水层见 test/Cases/Command/CreateAdminCommandTest.php。
 *
 * `super_admin` 角色名和权限编码是全局唯一的固定字面量，所以只在调用前这一行确实不存在、
 * 由本用例新建出来的情况下，才在 tearDown 里删掉，避免删掉共享库里已有的同名行。
 *
 * @internal
 * @coversNothing
 */
class AdminBootstrapServiceTest extends TestCase
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

    public function testFreshRunCreatesRolePermissionsAndAdminUser()
    {
        $this->rememberOwnershipBeforeCall();
        $username = $this->uniqueUsername();

        $service = $this->getContainer()->get(AdminBootstrapService::class);
        $admin = $service->createSuperAdmin($username, 'a-strong-password', 'Root Admin');
        $this->adminUserIds[] = $admin->id;

        $role = AdminRole::where('name', self::ROLE_NAME)->first();
        $this->assertNotNull($role);
        $this->assertTrue((bool) $role->is_system);

        $permission = AdminPermission::where('code', self::PERMISSION_CODE)->first();
        $this->assertNotNull($permission);

        $this->assertTrue(
            AdminRolePermission::where('role_id', $role->id)->where('permission_id', $permission->id)->exists()
        );

        $admin->refresh();
        $this->assertSame($username, $admin->username);
        $this->assertSame('Root Admin', $admin->real_name);
        $this->assertSame($role->id, $admin->role_id);
        $this->assertSame('active', $admin->status);
        $this->assertTrue(password_verify('a-strong-password', $admin->password));
    }

    public function testRealNameDefaultsToUsernameWhenOmitted()
    {
        $this->rememberOwnershipBeforeCall();
        $username = $this->uniqueUsername();

        $service = $this->getContainer()->get(AdminBootstrapService::class);
        $admin = $service->createSuperAdmin($username, 'a-strong-password');
        $this->adminUserIds[] = $admin->id;

        $this->assertSame($username, $admin->real_name);
    }

    public function testRerunWithDifferentUsernameReusesRoleAndPermissionsWithoutDuplicating()
    {
        $this->rememberOwnershipBeforeCall();

        $service = $this->getContainer()->get(AdminBootstrapService::class);

        $first = $service->createSuperAdmin($this->uniqueUsername(), 'a-strong-password');
        $this->adminUserIds[] = $first->id;

        $second = $service->createSuperAdmin($this->uniqueUsername(), 'another-strong-pw');
        $this->adminUserIds[] = $second->id;

        $this->assertSame(1, AdminRole::where('name', self::ROLE_NAME)->count());
        $this->assertSame(1, AdminPermission::where('code', self::PERMISSION_CODE)->count());

        $role = AdminRole::where('name', self::ROLE_NAME)->first();
        $permission = AdminPermission::where('code', self::PERMISSION_CODE)->first();
        $this->assertSame(
            1,
            AdminRolePermission::where('role_id', $role->id)->where('permission_id', $permission->id)->count()
        );

        $this->assertSame($role->id, $first->role_id);
        $this->assertSame($role->id, $second->role_id);
        $this->assertNotSame($first->id, $second->id);
    }

    public function testDuplicateUsernameFailsCleanlyWithoutCreatingASecondRow()
    {
        $this->rememberOwnershipBeforeCall();
        $username = $this->uniqueUsername();

        $service = $this->getContainer()->get(AdminBootstrapService::class);
        $original = $service->createSuperAdmin($username, 'a-strong-password');
        $this->adminUserIds[] = $original->id;

        try {
            $service->createSuperAdmin($username, 'a-different-password');
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($username, $e->getMessage());
        }

        $this->assertSame(1, AdminUser::where('username', $username)->count());
        $original->refresh();
        $this->assertTrue(password_verify('a-strong-password', $original->password));
    }

    public function testPasswordTooShortIsRejectedBeforeTouchingTheDatabase()
    {
        $roleExistedBefore = AdminRole::where('name', self::ROLE_NAME)->exists();
        $username = $this->uniqueUsername();

        $service = $this->getContainer()->get(AdminBootstrapService::class);

        try {
            $service->createSuperAdmin($username, 'short');
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('8', $e->getMessage());
        }

        $this->assertSame(0, AdminUser::where('username', $username)->count());
        // 密码校验失败必须在任何 DB 写入之前发生：如果角色本来不存在，
        // 这次失败的调用不应该把它建出来。
        $this->assertSame($roleExistedBefore, AdminRole::where('name', self::ROLE_NAME)->exists());
    }

    /**
     * 已有的超级管理员账号在新增权限编码后，靠 syncSuperAdminPermissions() 补齐，
     * 重复执行不会重复授予。
     */
    public function testSyncGrantsMissingKnownPermissionsToExistingSuperAdminAndIsIdempotent()
    {
        $this->rememberOwnershipBeforeCall();
        $service = $this->getContainer()->get(AdminBootstrapService::class);
        $admin = $service->createSuperAdmin($this->uniqueUsername(), 'a-strong-password');
        $this->adminUserIds[] = $admin->id;

        // 模拟"上线了一个新权限，但角色上还没有"：把 admin_user.view 的授权拿掉
        $permission = AdminPermission::where('code', self::PERMISSION_CODE)->firstOrFail();
        AdminRolePermission::where('role_id', $admin->role_id)->where('permission_id', $permission->id)->delete();
        $dao = $this->getContainer()->get(AdminRolePermissionDao::class);
        $this->assertFalse($dao->roleHasPermission($admin->role_id, self::PERMISSION_CODE));

        $this->assertSame(1, $service->syncSuperAdminPermissions());
        $this->assertTrue($dao->roleHasPermission($admin->role_id, self::PERMISSION_CODE));
        $this->assertTrue($dao->roleHasPermission($admin->role_id, 'operation_log.view'), '其它已知权限也都在');

        $this->assertSame(0, $service->syncSuperAdminPermissions());
        $this->assertSame(1, AdminRolePermission::where('role_id', $admin->role_id)->where('permission_id', $permission->id)->count());
    }

    public function testSyncDoesNothingWhenSuperAdminRoleDoesNotExist()
    {
        if (AdminRole::where('name', self::ROLE_NAME)->exists()) {
            $this->markTestSkipped('共享库里已有 super_admin 角色，无法验证角色不存在的分支');
        }

        $this->assertNull($this->getContainer()->get(AdminBootstrapService::class)->syncSuperAdminPermissions());
        $this->assertFalse(AdminRole::where('name', self::ROLE_NAME)->exists(), '同步不应该凭空建出空角色');
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
