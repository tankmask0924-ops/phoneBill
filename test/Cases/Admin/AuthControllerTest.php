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
use HyperfTest\HttpTestCase;

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
}
