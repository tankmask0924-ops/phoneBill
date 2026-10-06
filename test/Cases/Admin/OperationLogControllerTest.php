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

use HyperfTest\HttpTestCase;

/**
 * 操作日志列表：写操作会记日志、按操作人筛选、筛不出数据时返回空列表。
 *
 * @internal
 * @coversNothing
 */
class OperationLogControllerTest extends HttpTestCase
{
    use CreatesAdmins;

    private const PASSWORD = 'correct-password';

    protected function tearDown(): void
    {
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testWriteOperationIsLoggedAndListedForItsOperator()
    {
        $operator = $this->createAdminWithPermissions(['role.manage', 'operation_log.view']);
        $token = $this->loginAs($operator);
        $role = $this->createRole([]);

        $this->assertSame(200, $this->jsonRequest('PUT', "/admin/roles/{$role->id}", $token, ['remark' => '改个备注'])->getStatusCode());

        $response = $this->jsonRequest('GET', '/admin/operation-logs?admin_user_id=' . $operator->id, $token);
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['total']);
        $this->assertSame('update_role', $body['data'][0]['action']);
        $this->assertSame($role->id, $body['data'][0]['target_id']);
        $this->assertSame($operator->username, $body['data'][0]['admin_username']);
    }

    public function testListWithNoMatchReturnsEmpty()
    {
        $operator = $this->createAdminWithPermissions(['operation_log.view']);

        $response = $this->jsonRequest('GET', '/admin/operation-logs?admin_user_id=' . $operator->id, $this->loginAs($operator));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->body($response)['data']);
        $this->assertSame(0, $this->body($response)['total']);
    }

    public function testWithoutPermissionReturns403()
    {
        $token = $this->loginAs($this->createAdminWithPermissions([]));

        $this->assertSame(403, $this->jsonRequest('GET', '/admin/operation-logs', $token)->getStatusCode());
    }
}
