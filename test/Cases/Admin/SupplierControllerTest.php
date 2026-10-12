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
use App\Model\SystemSetting;
use App\Security\Encrypter;
use App\Service\Admin\SupplierAdminService;
use App\Service\Supplier\SupplierGateway;
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
        ]);
        $body = $this->body($created);
        $this->assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        $this->supplierIds[] = $body['id'];
        $this->assertArrayNotHasKey('provinces', $body, '覆盖省份在供应商商品上');
        $this->assertSame(['result' => 'success', 'api_url' => 'https://example.test'], $body['config']);
        $this->assertSame(['secret'], $body['configured_secrets']);
        $this->assertStringNotContainsString('top-secret-value', (string) $created->getBody());
        $this->assertStringNotContainsString('top-secret-value', (string) Supplier::find($body['id'])->config, '库里是密文');

        // 密钥留空：沿用原来的
        $updated = $this->body($this->jsonRequest('PUT', "/admin/suppliers/{$body['id']}", $token, [
            'name' => $body['name'] . '改',
            'config' => ['result' => 'failed', 'api_url' => '', 'secret' => ''],
        ]));
        $this->assertSame(['secret'], $updated['configured_secrets']);
        $config = make(SupplierAdminService::class)->decryptConfig(Supplier::find($body['id']));
        $this->assertSame(['result' => 'failed', 'api_url' => '', 'secret' => 'top-secret-value'], $config);

        $list = $this->body($this->jsonRequest('GET', '/admin/suppliers?keyword=' . $code, $token));
        $this->assertSame(1, $list['total']);
        $this->assertSame(0, $list['data'][0]['product_count']);

        $meta = $this->body($this->jsonRequest('GET', '/admin/suppliers/meta', $token));
        $this->assertContains('mock', array_column($meta['drivers'], 'code'));
        $this->assertCount(31, $meta['provinces']);
    }

    public function testNotifyUrlIsGeneratedFromSystemSetting()
    {
        $original = SystemSetting::where('key', SupplierGateway::NOTIFY_BASE_SETTING)->first()?->value;
        SystemSetting::where('key', SupplierGateway::NOTIFY_BASE_SETTING)->delete();
        try {
            $token = $this->loginAs($this->createAdminWithPermissions(['supplier.view', 'supplier.manage', 'setting.manage']));
            $gateway = make(SupplierGateway::class);
            $supplier = $this->createSupplier();

            // 没配回调域名：沿用以前手填存下的回调地址
            $supplier->update(['config' => make(Encrypter::class)->encrypt(json_encode(['result' => 'success', 'notify_url' => 'http://old.test/notify/supplier/x']))]);
            $this->assertSame('http://old.test/notify/supplier/x', $gateway->driverConfig(Supplier::find($supplier->id))['notify_url']);
            $row = $this->body($this->jsonRequest('GET', '/admin/suppliers?keyword=' . $supplier->code, $token))['data'][0];
            $this->assertFalse($row['notify_url_generated']);

            // 配了回调域名：按域名 + 编码生成，不带路径、末尾斜杠去掉
            foreach (['', 'ftp://x.test', 'https://x.test/path', 'not a url'] as $bad) {
                $this->assertSame(422, $this->jsonRequest('PUT', '/admin/settings/notify', $token, ['base_url' => $bad])->getStatusCode(), $bad);
            }
            $saved = $this->body($this->jsonRequest('PUT', '/admin/settings/notify', $token, ['base_url' => 'https://pay.example.com/']));
            $this->assertSame(['base_url' => 'https://pay.example.com'], $saved);
            $expected = "https://pay.example.com/notify/supplier/{$supplier->code}";
            $this->assertSame($expected, $gateway->driverConfig(Supplier::find($supplier->id))['notify_url']);
            $row = $this->body($this->jsonRequest('GET', '/admin/suppliers?keyword=' . $supplier->code, $token))['data'][0];
            $this->assertSame($expected, $row['notify_url']);
            $this->assertTrue($row['notify_url_generated']);

            // 驱动的参数表单里不再有回调地址
            $meta = $this->body($this->jsonRequest('GET', '/admin/suppliers/meta', $token));
            foreach ($meta['drivers'] as $driver) {
                $this->assertNotContains('notify_url', array_column($driver['config_schema'], 'key'), $driver['code']);
            }
        } finally {
            SystemSetting::where('key', SupplierGateway::NOTIFY_BASE_SETTING)->delete();
            if ($original !== null) {
                SystemSetting::create(['key' => SupplierGateway::NOTIFY_BASE_SETTING, 'value' => $original]);
            }
        }
    }

    public function testValidation()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['supplier.manage']));
        $existing = $this->createSupplier();
        $valid = ['name' => $this->uniq('供应商'), 'code' => $this->uniq('s_'), 'driver' => 'mock', 'config' => ['result' => 'success']];

        foreach ([
            ['code' => 'Bad-Code'],
            ['code' => $existing->code],
            ['name' => $existing->name],
            ['driver' => 'no_such_driver'],
            ['config' => ['result' => '']],
            ['config' => ['result' => 'maybe']],
        ] as $override) {
            $response = $this->jsonRequest('POST', '/admin/suppliers', $token, array_merge($valid, $override));
            $this->assertSame(422, $response->getStatusCode(), json_encode($override, JSON_UNESCAPED_UNICODE));
        }

        $this->assertSame(422, $this->jsonRequest('PUT', "/admin/suppliers/{$existing->id}", $token, ['code' => 'other_code'])->getStatusCode());
        $this->assertSame(404, $this->jsonRequest('PUT', '/admin/suppliers/999999999', $token, ['name' => 'x'])->getStatusCode());
    }

    public function testBalance()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['supplier.view']));
        $supplier = $this->createSupplier();

        $this->assertSame(['supported' => true, 'balance' => '99999.00', 'error' => null], $this->body($this->jsonRequest('GET', "/admin/suppliers/{$supplier->id}/balance", $token)));

        // 上游出错、驱动没登记：不报 500，错误放在 error 里
        $this->setMockResult($supplier, 'timeout');
        $failed = $this->body($this->jsonRequest('GET', "/admin/suppliers/{$supplier->id}/balance", $token));
        $this->assertNull($failed['balance']);
        $this->assertStringContainsString('模拟超时', $failed['error']);
        $supplier->update(['driver' => 'no_such_driver']);
        $this->assertNotNull($this->body($this->jsonRequest('GET', "/admin/suppliers/{$supplier->id}/balance", $token))['error']);

        $this->assertSame(404, $this->jsonRequest('GET', '/admin/suppliers/999999999/balance', $token)->getStatusCode());
        $this->assertSame(403, $this->jsonRequest('GET', "/admin/suppliers/{$supplier->id}/balance", $this->loginAs($this->createAdminWithPermissions(['order.view'])))->getStatusCode());
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
