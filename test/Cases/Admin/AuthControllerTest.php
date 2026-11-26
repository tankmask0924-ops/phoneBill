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

namespace HyperfTest\Cases\Admin;

use App\Model\AdminUser;
use App\Service\Admin\AdminBootstrapService;
use HyperfTest\HttpTestCase;
use InvalidArgumentException;

use function Hyperf\Support\make;

/**
 * 登录、/admin/auth/me 的端到端测试，走真实路由 + 中间件栈。
 *
 * @internal
 * @coversNothing
 */
class AuthControllerTest extends HttpTestCase
{
    use CreatesAdmins;

    private const PASSWORD = 'correct-password';

    protected function tearDown(): void
    {
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testLoginWithCorrectCredentialsSucceedsAndUpdatesLastLoginAt()
    {
        $admin = $this->createAdminWithPermissions([]);
        $this->assertNull($admin->last_login_at);

        $response = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => ['username' => $admin->username, 'password' => self::PASSWORD],
        ]);

        $body = $this->body($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($body['token']);
        $this->assertSame($admin->username, $body['username']);
        $this->assertNotNull(AdminUser::find($admin->id)->last_login_at);
    }

    public function testWrongPasswordAndUnknownUsernameShareTheSameGenericMessage()
    {
        $admin = $this->createAdminWithPermissions([]);

        $wrongPassword = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => ['username' => $admin->username, 'password' => 'totally-wrong-password'],
        ]);
        $unknownUsername = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => ['username' => 'no-such-admin-' . uniqid('', true), 'password' => 'whatever'],
        ]);

        $this->assertSame(401, $wrongPassword->getStatusCode());
        $this->assertSame(401, $unknownUsername->getStatusCode());
        $this->assertSame((string) $wrongPassword->getBody(), (string) $unknownUsername->getBody());
    }

    public function testDisabledAdminIsBlockedDespiteCorrectPassword()
    {
        $admin = $this->createAdmin($this->createRole([])->id, 'disabled');

        $this->assertNull($this->loginAs($admin));
        $response = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => ['username' => $admin->username, 'password' => self::PASSWORD],
        ]);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testFifthConsecutiveWrongPasswordLocksTheAccount()
    {
        $admin = $this->createAdminWithPermissions([]);

        // 前 4 次和用户名不存在一样，只提示账号或密码错误
        for ($i = 1; $i <= 4; ++$i) {
            $response = $this->login($admin->username, 'wrong');
            $this->assertSame(401, $response->getStatusCode(), "第 {$i} 次");
            $this->assertSame('账号或密码错误', (string) $response->getBody());
        }
        $this->assertSame('active', AdminUser::find($admin->id)->status);

        // 第 5 次封禁
        $response = $this->login($admin->username, 'wrong');
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('密码连续输错 5 次，账号已被封禁，请联系超级管理员', (string) $response->getBody());
        $locked = AdminUser::find($admin->id);
        $this->assertSame('disabled', $locked->status);
        $this->assertSame(5, $locked->login_failures);

        // 封禁后密码对了也登不上，再输错也一样提示封禁
        $this->assertSame(403, $this->login($admin->username, self::PASSWORD)->getStatusCode());
        $this->assertSame(403, $this->login($admin->username, 'wrong')->getStatusCode());
        $this->assertSame('disabled', AdminUser::find($admin->id)->status);
    }

    public function testSuccessfulLoginResetsTheFailureCount()
    {
        $admin = $this->createAdminWithPermissions([]);

        for ($i = 0; $i < 4; ++$i) {
            $this->assertSame(401, $this->login($admin->username, 'wrong')->getStatusCode());
        }
        $this->assertNotNull($this->loginAs($admin));
        $this->assertSame(0, AdminUser::find($admin->id)->login_failures);

        // 清零后重新算：再错 4 次也不封
        for ($i = 0; $i < 4; ++$i) {
            $this->assertSame(401, $this->login($admin->username, 'wrong')->getStatusCode());
        }
        $this->assertSame('active', AdminUser::find($admin->id)->status);
        $this->assertNotNull($this->loginAs($admin));
    }

    public function testReEnablingInAdminUsersUnlocksAndResetsTheCount()
    {
        $admin = $this->createAdminWithPermissions([]);
        for ($i = 0; $i < 5; ++$i) {
            $this->login($admin->username, 'wrong');
        }
        $operatorToken = $this->loginAs($this->createSuperAdmin());

        $list = $this->body($this->jsonRequest('GET', '/admin/admin-users?keyword=' . $admin->username, $operatorToken));
        $row = array_values(array_filter($list['data'] ?? [], static fn ($r) => $r['id'] === $admin->id))[0] ?? null;
        $this->assertNotNull($row, json_encode($list));
        $this->assertSame(['disabled', 5], [$row['status'], $row['login_failures']], '列表里能看出是密码错误封禁');

        $response = $this->jsonRequest('POST', "/admin/admin-users/{$admin->id}/status", $operatorToken, ['status' => 'active']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0, AdminUser::find($admin->id)->login_failures);
        $this->assertNotNull($this->loginAs($admin));
    }

    public function testUnlockCommandServiceReactivatesTheAdmin()
    {
        $admin = $this->createAdminWithPermissions([]);
        for ($i = 0; $i < 5; ++$i) {
            $this->login($admin->username, 'wrong');
        }
        $this->assertSame('disabled', AdminUser::find($admin->id)->status);

        make(AdminBootstrapService::class)->unlockAdmin(' ' . $admin->username . ' ');
        $unlocked = AdminUser::find($admin->id);
        $this->assertSame(['active', 0], [$unlocked->status, $unlocked->login_failures]);
        $this->assertNotNull($this->loginAs($admin));

        $this->expectException(InvalidArgumentException::class);
        make(AdminBootstrapService::class)->unlockAdmin('no-such-admin-' . uniqid('', true));
    }

    public function testUnknownUsernameIsNeverLocked()
    {
        $username = 'no-such-admin-' . uniqid('', true);
        for ($i = 0; $i < 6; ++$i) {
            $this->assertSame(401, $this->login($username, 'wrong')->getStatusCode());
        }
    }

    public function testMeReturnsSafeFieldsAndPermissionCodes()
    {
        $admin = $this->createAdminWithPermissions(['role.view', 'admin_user.view']);

        $response = $this->jsonRequest('GET', '/admin/auth/me', $this->loginAs($admin));
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($admin->id, $body['id']);
        $this->assertSame($admin->username, $body['username']);
        $this->assertSame('active', $body['status']);
        $this->assertSame(['admin_user.view', 'role.view'], $body['permissions']);
        $this->assertFalse($body['is_super_admin']);
        $this->assertArrayNotHasKey('password', $body);

        $super = $this->body($this->jsonRequest('GET', '/admin/auth/me', $this->loginAs($this->createSuperAdmin())));
        $this->assertTrue($super['is_super_admin']);
        $this->assertContains('operation_log.view', $super['permissions']);
    }

    public function testMeWithMissingOrInvalidTokenReturns401()
    {
        $this->assertSame(401, $this->jsonRequest('GET', '/admin/auth/me', null)->getStatusCode());
        $this->assertSame(401, $this->jsonRequest('GET', '/admin/auth/me', 'garbage-token-' . uniqid('', true))->getStatusCode());
    }

    public function testChangeOwnPasswordInvalidatesOldToken()
    {
        $admin = $this->createAdminWithPermissions([]);
        $oldToken = $this->loginAs($admin);

        $this->assertSame(422, $this->jsonRequest('PUT', '/admin/auth/password', $oldToken, ['old_password' => 'wrong-password', 'new_password' => 'another-password'])->getStatusCode());
        $response = $this->jsonRequest('PUT', '/admin/auth/password', $oldToken, ['old_password' => self::PASSWORD, 'new_password' => 'another-password']);
        $newToken = $this->body($response)['token'] ?? null;

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(401, $this->jsonRequest('GET', '/admin/auth/me', $oldToken)->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('GET', '/admin/auth/me', $newToken)->getStatusCode());
        $this->assertNotNull($this->loginAs($admin, 'another-password'));
    }

    private function login(string $username, string $password)
    {
        return $this->client->request('POST', '/admin/auth/login', [
            'form_params' => ['username' => $username, 'password' => $password],
        ]);
    }
}
