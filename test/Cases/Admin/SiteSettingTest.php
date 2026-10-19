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

use App\Model\SystemSetting;
use App\Service\Setting\SiteSettingService;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 系统配置 - 基本设置：系统名称，登录页不用登录也能读到。
 *
 * @internal
 * @coversNothing
 */
class SiteSettingTest extends HttpTestCase
{
    use CreatesAdmins;

    private const PASSWORD = 'correct-password';

    /** 测试前的设置（测试库和开发库共用，测完还原） */
    private ?string $original = null;

    private bool $hadSetting = false;

    protected function setUp(): void
    {
        parent::setUp();
        $row = SystemSetting::where('key', SiteSettingService::KEY)->first();
        $this->hadSetting = $row !== null;
        $this->original = $row?->value;
    }

    protected function tearDown(): void
    {
        if ($this->hadSetting) {
            SystemSetting::where('key', SiteSettingService::KEY)->update(['value' => $this->original]);
        } else {
            SystemSetting::where('key', SiteSettingService::KEY)->delete();
        }
        make(SiteSettingService::class)->flush();
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testSiteName()
    {
        $admin = $this->loginAs($this->createAdminWithPermissions(['setting.manage']));

        $this->assertSame(422, $this->jsonRequest('PUT', '/admin/settings/site', $admin, ['name' => str_repeat('名', 31)])->getStatusCode());
        $this->assertSame(['name' => '话费充值平台'], $this->body($this->jsonRequest('PUT', '/admin/settings/site', $admin, ['name' => '  话费充值平台 '])));
        $this->assertSame(['name' => '话费充值平台'], $this->body($this->jsonRequest('GET', '/admin/settings/site', $admin)));

        // 不用登录就能读到（保存时清了缓存，立即生效）
        $public = $this->client->request('GET', '/admin/settings/public');
        $this->assertSame(200, $public->getStatusCode());
        $this->assertSame(['site_name' => '话费充值平台'], $this->body($public));

        // 留空：前端用默认名称
        $this->assertSame(['name' => ''], $this->body($this->jsonRequest('PUT', '/admin/settings/site', $admin, ['name' => ''])));
        $this->assertSame(['site_name' => ''], $this->body($this->client->request('GET', '/admin/settings/public')));

        $viewer = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        $this->assertSame(403, $this->jsonRequest('PUT', '/admin/settings/site', $viewer, ['name' => 'x'])->getStatusCode());
    }
}
