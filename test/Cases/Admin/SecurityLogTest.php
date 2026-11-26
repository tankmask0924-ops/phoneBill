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

use App\Model\MerchantUser;
use App\Model\SecurityLog;
use App\Service\Auth\LoginLockoutService;
use HyperfTest\HttpTestCase;

/**
 * 登录与账号安全日志：两端登录成功 / 失败、改密码、商户自助重置密钥都留痕（带 IP、User-Agent），后台能按条件查。
 *
 * @internal
 * @coversNothing
 */
class SecurityLogTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

    private const USER_AGENT = 'SecurityLogTest/1.0';

    /** 本用例用到的不存在的用户名，tearDown 时按它清掉 user_id 为空的记录 */
    private array $unknownUsernames = [];

    protected function tearDown(): void
    {
        if ($this->unknownUsernames !== []) {
            SecurityLog::whereNull('user_id')->whereIn('username', $this->unknownUsernames)->delete();
        }
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testAdminLoginFailureAndSuccessAreLoggedWithIpAndUserAgent()
    {
        $admin = $this->createAdminWithPermissions([]);

        $this->assertSame(401, $this->adminLogin($admin->username, 'wrong')->getStatusCode());
        $this->assertSame(200, $this->adminLogin($admin->username, self::PASSWORD)->getStatusCode());

        $logs = $this->logsFor(SecurityLog::PORTAL_ADMIN, $admin->username);
        $this->assertSame([SecurityLog::EVENT_LOGIN_FAILED, SecurityLog::EVENT_LOGIN_SUCCESS], array_column($logs, 'event'));
        $this->assertEquals(['reason' => SecurityLog::REASON_WRONG_PASSWORD, 'failures' => 1, 'locked' => false], $logs[0]['detail']);
        $this->assertSame($admin->id, $logs[1]['user_id']);
        $this->assertSame('127.0.0.1', $logs[1]['ip']);
        $this->assertSame(self::USER_AGENT, $logs[1]['user_agent']);
    }

    public function testUnknownUsernameIsLoggedWithoutUserId()
    {
        $username = $this->unknownUsernames[] = 'nobody_' . uniqid();

        $this->assertSame(401, $this->adminLogin($username, 'whatever')->getStatusCode());

        $logs = $this->logsFor(SecurityLog::PORTAL_ADMIN, $username);
        $this->assertCount(1, $logs);
        $this->assertNull($logs[0]['user_id']);
        $this->assertEquals(['reason' => SecurityLog::REASON_UNKNOWN_USER], $logs[0]['detail']);
    }

    public function testLockingFailureIsMarkedAndLaterAttemptsShowDisabled()
    {
        $admin = $this->createAdminWithPermissions([]);
        for ($i = 0; $i < LoginLockoutService::MAX_FAILURES; ++$i) {
            $this->adminLogin($admin->username, 'wrong');
        }
        // 封禁以后密码对了也登不上，记成「账号已禁用」
        $this->assertSame(403, $this->adminLogin($admin->username, self::PASSWORD)->getStatusCode());

        $logs = $this->logsFor(SecurityLog::PORTAL_ADMIN, $admin->username);
        $this->assertCount(LoginLockoutService::MAX_FAILURES + 1, $logs);
        $this->assertEquals(['reason' => SecurityLog::REASON_WRONG_PASSWORD, 'failures' => 5, 'locked' => true], $logs[4]['detail']);
        $this->assertEquals(['reason' => SecurityLog::REASON_DISABLED], $logs[5]['detail']);
    }

    public function testAdminChangingOwnPasswordIsLogged()
    {
        $admin = $this->createAdminWithPermissions([]);
        $token = $this->loginAs($admin);

        $response = $this->jsonRequest('PUT', '/admin/auth/password', $token, ['old_password' => self::PASSWORD, 'new_password' => 'another-password']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertContains(SecurityLog::EVENT_CHANGE_PASSWORD, array_column($this->logsFor(SecurityLog::PORTAL_ADMIN, $admin->username), 'event'));
    }

    public function testMerchantLoginAndSecretResetAreLoggedWithMerchant()
    {
        $merchant = $this->createMerchant();
        $user = MerchantUser::create([
            'merchant_id' => $merchant->id,
            'username' => $this->uniq('mu_'),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => 'active',
        ]);

        $this->assertSame(401, $this->merchantLogin($user->username, 'wrong')->getStatusCode());
        $token = $this->body($this->merchantLogin($user->username, self::PASSWORD))['token'];
        $reset = $this->jsonRequest('POST', '/merchant/account/reset-secret', $token, ['password' => self::PASSWORD]);
        $this->assertSame(200, $reset->getStatusCode(), (string) $reset->getBody());

        $logs = $this->logsFor(SecurityLog::PORTAL_MERCHANT, $user->username);
        $this->assertSame(
            [SecurityLog::EVENT_LOGIN_FAILED, SecurityLog::EVENT_LOGIN_SUCCESS, SecurityLog::EVENT_RESET_SECRET],
            array_column($logs, 'event'),
        );
        $this->assertSame([$merchant->id, $merchant->id, $merchant->id], array_column($logs, 'merchant_id'));
        $this->assertSame('127.0.0.1', $logs[2]['ip']);
    }

    public function testAdminListsLogsByUsernameWithMerchantName()
    {
        $merchant = $this->createMerchant();
        $user = MerchantUser::create([
            'merchant_id' => $merchant->id,
            'username' => $this->uniq('mu_'),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => 'active',
        ]);
        $this->merchantLogin($user->username, self::PASSWORD);
        $token = $this->loginAs($this->createAdminWithPermissions(['operation_log.view']));

        $response = $this->jsonRequest('GET', '/admin/security-logs?portal=merchant&username=' . $user->username, $token);
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['total']);
        $this->assertSame(SecurityLog::EVENT_LOGIN_SUCCESS, $body['data'][0]['event']);
        $this->assertSame($merchant->name, $body['data'][0]['merchant_name']);
        $this->assertSame(self::USER_AGENT, $body['data'][0]['user_agent']);
    }

    public function testListWithNoMatchReturnsEmpty()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['operation_log.view']));

        $response = $this->jsonRequest('GET', '/admin/security-logs?username=nobody_' . uniqid(), $token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->body($response)['data']);
        $this->assertSame(0, $this->body($response)['total']);
    }

    public function testListWithoutPermissionReturns403()
    {
        $token = $this->loginAs($this->createAdminWithPermissions([]));

        $this->assertSame(403, $this->jsonRequest('GET', '/admin/security-logs', $token)->getStatusCode());
    }

    /**
     * @return list<array<string, mixed>> 按时间先后
     */
    private function logsFor(string $portal, string $username): array
    {
        return SecurityLog::where('portal', $portal)->where('username', $username)->orderBy('id')->get()->toArray();
    }

    private function adminLogin(string $username, string $password)
    {
        return $this->client->request('POST', '/admin/auth/login', [
            'headers' => ['Content-Type' => 'application/json', 'User-Agent' => self::USER_AGENT],
            'json' => ['username' => $username, 'password' => $password],
        ]);
    }

    private function merchantLogin(string $username, string $password)
    {
        return $this->client->request('POST', '/merchant/auth/login', [
            'headers' => ['Content-Type' => 'application/json', 'User-Agent' => self::USER_AGENT],
            'json' => ['username' => $username, 'password' => $password],
        ]);
    }
}
