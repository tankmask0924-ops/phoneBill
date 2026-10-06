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

use App\Model\Supplier;
use App\Service\Admin\SupplierAdminService;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 供应商：增改、接口参数加密与密钥不回显、省份、启停、权限。
 *
 * @internal
 * @coversNothing
 */
class SupplierControllerTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

    protected function tearDown(): void
    {
        $this->cleanUpCatalog();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testCreateUpdateAndListSupplier()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['supplier.view', 'supplier.manage']));
        $code = $this->uniq('s_');

        $created = $this->jsonRequest('POST', '/admin/suppliers', $token, [
            'name' => $this->uniq('供应商'),
            'code' => $code,
            'driver' => 'mock',
            'config' => ['result' => 'success', 'api_url' => 'https://example.test', 'secret' => 'top-secret-value'],
            'provinces' => ['广东', '湖南'],
        ]);
        $body = $this->body($created);
        $this->assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        $this->supplierIds[] = $body['id'];
        $this->assertSame(['湖南', '广东'], $body['provinces'], '按省份表的顺序排');
        $this->assertSame(['result' => 'success', 'api_url' => 'https://example.test'], $body['config']);
        $this->assertSame(['secret'], $body['configured_secrets']);
        $this->assertStringNotContainsString('top-secret-value', (string) $created->getBody());
        $this->assertStringNotContainsString('top-secret-value', (string) Supplier::find($body['id'])->config, '库里是密文');

        // 密钥留空：沿用原来的
        $updated = $this->body($this->jsonRequest('PUT', "/admin/suppliers/{$body['id']}", $token, [
            'name' => $body['name'] . '改',
            'config' => ['result' => 'failed', 'api_url' => '', 'secret' => ''],
            'provinces' => ['广东', '*'],
        ]));
        $this->assertSame(['*'], $updated['provinces'], '选了全国只保留 *');
        $this->assertSame(['secret'], $updated['configured_secrets']);
        $config = make(SupplierAdminService::class)->decryptConfig(Supplier::find($body['id']));
        $this->assertSame(['result' => 'failed', 'api_url' => '', 'secret' => 'top-secret-value'], $config);

        $list = $this->body($this->jsonRequest('GET', '/admin/suppliers?keyword=' . $code, $token));
        $this->assertSame(1, $list['total']);
        $this->assertSame(0, $list['data'][0]['product_count']);

        $byProvince = $this->body($this->jsonRequest('GET', '/admin/suppliers?province=' . urlencode('西藏') . '&keyword=' . $code, $token));
        $this->assertSame(1, $byProvince['total'], '覆盖全国的供应商按任何省份都能筛到');

        $meta = $this->body($this->jsonRequest('GET', '/admin/suppliers/meta', $token));
        $this->assertContains('mock', array_column($meta['drivers'], 'code'));
        $this->assertCount(31, $meta['provinces']);
    }

    public function testValidation()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['supplier.manage']));
        $existing = $this->createSupplier();
        $valid = ['name' => $this->uniq('供应商'), 'code' => $this->uniq('s_'), 'driver' => 'mock', 'config' => ['result' => 'success'], 'provinces' => ['广东']];

        foreach ([
            ['code' => 'Bad-Code'],
            ['code' => $existing->code],
            ['name' => $existing->name],
            ['driver' => 'no_such_driver'],
            ['config' => ['result' => '']],
            ['config' => ['result' => 'maybe']],
            ['provinces' => []],
            ['provinces' => ['火星']],
        ] as $override) {
            $response = $this->jsonRequest('POST', '/admin/suppliers', $token, array_merge($valid, $override));
            $this->assertSame(422, $response->getStatusCode(), json_encode($override, JSON_UNESCAPED_UNICODE));
        }

        $this->assertSame(422, $this->jsonRequest('PUT', "/admin/suppliers/{$existing->id}", $token, ['code' => 'other_code'])->getStatusCode());
        $this->assertSame(404, $this->jsonRequest('PUT', '/admin/suppliers/999999999', $token, ['name' => 'x'])->getStatusCode());
    }

    public function testChangeStatusAndPermissions()
    {
        $supplier = $this->createSupplier();
        $viewer = $this->loginAs($this->createAdminWithPermissions(['supplier.view']));
        $this->assertSame(403, $this->jsonRequest('POST', "/admin/suppliers/{$supplier->id}/status", $viewer, ['status' => 'disabled'])->getStatusCode());

        $token = $this->loginAs($this->createAdminWithPermissions(['supplier.manage']));
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/suppliers/{$supplier->id}/status", $token, ['status' => 'paused'])->getStatusCode());
        $this->assertSame('disabled', $this->body($this->jsonRequest('POST', "/admin/suppliers/{$supplier->id}/status", $token, ['status' => 'disabled']))['status']);
        $this->assertSame('disabled', Supplier::find($supplier->id)->status);
    }

    public function testListWithNoMatches()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['supplier.view']));
        $response = $this->jsonRequest('GET', '/admin/suppliers?keyword=' . $this->uniq('none_'), $token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->body($response)['data']);
    }
}
