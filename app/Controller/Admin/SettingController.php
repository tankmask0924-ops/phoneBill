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

namespace App\Controller\Admin;

use App\Annotation\RequiresPermission;
use App\Controller\AbstractController;
use App\Middleware\AdminAuthMiddleware;
use App\Middleware\AdminPermissionMiddleware;
use App\Model\AdminUser;
use App\Network\ClientIpResolver;
use App\Service\Admin\NotifySettingAdminService;
use App\Service\Admin\StorageSettingAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 系统配置：回调域名（NotifySettingAdminService）、图片存储（腾讯云 COS，StorageSettingAdminService）。
 */
#[Controller(prefix: '/admin/settings')]
class SettingController extends AbstractController
{
    #[Inject]
    protected StorageSettingAdminService $storageSettingService;

    #[Inject]
    protected NotifySettingAdminService $notifySettingService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('setting.manage')]
    #[GetMapping(path: 'storage')]
    public function storage(): array
    {
        return $this->storageSettingService->get();
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('setting.manage')]
    #[PutMapping(path: 'storage')]
    public function saveStorage(): array
    {
        return $this->storageSettingService->save($this->admin(), $this->request->all(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('setting.manage')]
    #[PostMapping(path: 'storage/test')]
    public function testStorage(): array
    {
        return $this->storageSettingService->test();
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('setting.manage')]
    #[GetMapping(path: 'notify')]
    public function notify(): array
    {
        return $this->notifySettingService->get();
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('setting.manage')]
    #[PutMapping(path: 'notify')]
    public function saveNotify(): array
    {
        return $this->notifySettingService->save($this->admin(), $this->request->input('base_url'), $this->clientIp());
    }

    private function admin(): AdminUser
    {
        /* @var AdminUser */
        return $this->request->getAttribute('admin');
    }

    private function clientIp(): ?string
    {
        return $this->clientIpResolver->resolve($this->request);
    }
}
