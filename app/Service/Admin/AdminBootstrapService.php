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

namespace App\Service\Admin;

use App\Dao\AdminPermissionDao;
use App\Dao\AdminRoleDao;
use App\Dao\AdminRolePermissionDao;
use App\Dao\AdminUserDao;
use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminUser;
use App\Service\AbstractService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use InvalidArgumentException;

/**
 * 管理后台账号、角色、权限的初始化，由 `admin:create` / `admin:sync-permissions` 两个命令调用。
 *
 * 可安全重复执行：角色、权限、角色-权限授权关系都是 find-or-create；只有 AdminUser
 * 按 username 唯一，重复用户名会被拒绝。
 */
class AdminBootstrapService extends AbstractService
{
    public const SUPER_ADMIN_ROLE_NAME = 'super_admin';

    /**
     * 代码里所有 #[RequiresPermission(...)] 用到的权限编码全集。
     *
     * **新增一个 #[RequiresPermission('some.code')] 时必须同步加到这里**，部署后执行
     * `admin:sync-permissions`。否则 admin_permissions 表里没有这个编码，
     * 包括超级管理员在内的所有角色访问该接口都会 403。
     *
     * @var array<int, array{code: string, module: string, name: string, type: string}>
     */
    public const KNOWN_PERMISSIONS = [
        ['code' => 'admin_user.view', 'module' => 'system', 'name' => '管理员账号查看', 'type' => 'action'],
        ['code' => 'admin_user.manage', 'module' => 'system', 'name' => '管理员账号管理', 'type' => 'action'],
        ['code' => 'role.view', 'module' => 'system', 'name' => '角色权限查看', 'type' => 'action'],
        ['code' => 'role.manage', 'module' => 'system', 'name' => '角色权限管理', 'type' => 'action'],
        ['code' => 'operation_log.view', 'module' => 'system', 'name' => '操作日志查看', 'type' => 'action'],
    ];

    /**
     * 预置角色的初始权限，只在角色不存在时按这里建一次，之后在后台怎么改都不会被覆盖。
     * 业务模块上线后在这里补上各角色该有的权限。
     *
     * @var array<string, array{remark: string, permissions: list<string>}>
     */
    public const PRESET_ROLES = [
        '运营' => [
            'remark' => '日常业务运营',
            'permissions' => [],
        ],
        '财务' => [
            'remark' => '资金与对账',
            'permissions' => ['operation_log.view'],
        ],
    ];

    /**
     * 密码最低长度，跟 AdminUserAdminService::MIN_PASSWORD_LENGTH 保持一致。
     */
    private const MIN_PASSWORD_LENGTH = 8;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    #[Inject]
    protected AdminRoleDao $adminRoleDao;

    #[Inject]
    protected AdminPermissionDao $adminPermissionDao;

    #[Inject]
    protected AdminRolePermissionDao $adminRolePermissionDao;

    /**
     * 创建一个拥有当前已知全部权限的超级管理员账号，find-or-create 角色/权限/授权关系，
     * 只有最终的 AdminUser 行是本次调用真正新建的东西。
     */
    public function createSuperAdmin(string $username, string $password, ?string $realName = null): AdminUser
    {
        $username = trim($username);
        $realName = $this->resolveRealName($realName, $username);

        $this->validate($username, $password);

        return Db::transaction(function () use ($username, $password, $realName) {
            $role = $this->ensureSuperAdminRole();
            $this->ensureRoleHasKnownPermissions($role);
            $this->ensurePresetRoles();

            return $this->adminUserDao->create([
                'username' => $username,
                'password' => password_hash($password, PASSWORD_BCRYPT),
                'real_name' => $realName,
                'role_id' => $role->id,
                'status' => 'active',
            ]);
        });
    }

    /**
     * 把 KNOWN_PERMISSIONS 里的权限补齐到超级管理员角色上（缺的权限行一并建出来）。
     * 新增权限编码后，已有的超级管理员账号不会自动拿到——createSuperAdmin() 只在
     * 新建账号时顺带补一次——部署后执行 `admin:sync-permissions` 调这里。幂等。
     * 还没有超级管理员角色（从没执行过 admin:create）时什么都不建，返回 null。
     *
     * @return null|int 这次新授予的权限数
     */
    public function syncSuperAdminPermissions(): ?int
    {
        $role = $this->findSuperAdminRole();
        if ($role === null) {
            return null;
        }

        return Db::transaction(function () use ($role) {
            $this->ensurePresetRoles();

            return $this->ensureRoleHasKnownPermissions($role);
        });
    }

    public function ensurePermission(string $code): AdminPermission
    {
        $permission = $this->adminPermissionDao->findByCode($code);
        if ($permission) {
            return $permission;
        }
        foreach (self::KNOWN_PERMISSIONS as $definition) {
            if ($definition['code'] === $code) {
                return $this->adminPermissionDao->create($definition);
            }
        }

        throw new InvalidArgumentException("未知的权限编码：{$code}");
    }

    private function validate(string $username, string $password): void
    {
        if ($username === '') {
            throw new InvalidArgumentException('username 不能为空');
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('密码长度至少 ' . self::MIN_PASSWORD_LENGTH . ' 位');
        }

        if ($this->adminUserDao->findByUsername($username)) {
            throw new InvalidArgumentException("用户名「{$username}」已存在");
        }
    }

    private function resolveRealName(?string $realName, string $username): string
    {
        $realName = $realName !== null ? trim($realName) : '';

        return $realName !== '' ? $realName : $username;
    }

    private function findSuperAdminRole(): ?AdminRole
    {
        return $this->adminRoleDao->newQuery()->where('name', self::SUPER_ADMIN_ROLE_NAME)->first();
    }

    private function ensureSuperAdminRole(): AdminRole
    {
        $role = $this->findSuperAdminRole();
        if ($role) {
            return $role;
        }

        return $this->adminRoleDao->create([
            'name' => self::SUPER_ADMIN_ROLE_NAME,
            'is_system' => true,
            'remark' => '超级管理员，拥有全部权限',
        ]);
    }

    private function ensurePresetRoles(): void
    {
        foreach (self::PRESET_ROLES as $name => $preset) {
            if ($this->adminRoleDao->newQuery()->where('name', $name)->exists()) {
                continue;
            }
            $role = $this->adminRoleDao->create(['name' => $name, 'is_system' => true, 'remark' => $preset['remark']]);
            foreach ($preset['permissions'] as $code) {
                $this->adminRolePermissionDao->create([
                    'role_id' => $role->id,
                    'permission_id' => $this->ensurePermission($code)->id,
                ]);
            }
        }
    }

    /**
     * @return int 新授予的权限数
     */
    private function ensureRoleHasKnownPermissions(AdminRole $role): int
    {
        $granted = 0;
        foreach (self::KNOWN_PERMISSIONS as $definition) {
            $permission = $this->ensurePermission($definition['code']);

            // 先查后插而不是直接 create() 再 catch 唯一约束冲突：这个方法只会被
            // admin:create / admin:sync-permissions 这两个单进程、串行执行的 CLI 命令调用，不存在并发写入
            // 同一个 (role_id, permission_id) 的场景，先查后插足够幂等，也比 try/catch
            // 唯一约束异常更直白。
            $alreadyGranted = $this->adminRolePermissionDao->newQuery()
                ->where('role_id', $role->id)
                ->where('permission_id', $permission->id)
                ->exists();

            if (! $alreadyGranted) {
                $this->adminRolePermissionDao->create([
                    'role_id' => $role->id,
                    'permission_id' => $permission->id,
                ]);
                ++$granted;
            }
        }

        return $granted;
    }
}
