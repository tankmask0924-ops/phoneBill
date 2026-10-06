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
use App\Enum\Province;
use App\Middleware\AdminAuthMiddleware;
use App\Middleware\AdminPermissionMiddleware;
use App\Model\AdminUser;
use App\Network\ClientIpResolver;
use App\Service\Admin\BlacklistAdminService;
use App\Service\Admin\MaintenanceAdminService;
use App\Service\Admin\SupplierAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 风控 - 号码黑名单、通道维护。号段查询在 SegmentController。
 */
#[Controller(prefix: '/admin/risk')]
class RiskController extends AbstractController
{
    #[Inject]
    protected BlacklistAdminService $blacklistAdminService;

    #[Inject]
    protected MaintenanceAdminService $maintenanceAdminService;

    #[Inject]
    protected SupplierAdminService $supplierAdminService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('risk.view')]
    #[GetMapping(path: 'blacklist')]
    public function blacklist(): array
    {
        return $this->blacklistAdminService->list($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('risk.manage')]
    #[PostMapping(path: 'blacklist')]
    public function addBlacklist(): array
    {
        return $this->blacklistAdminService->create($this->admin(), $this->request->all(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('risk.manage')]
    #[DeleteMapping(path: 'blacklist/{id:\d+}')]
    public function removeBlacklist(int $id): array
    {
        $this->blacklistAdminService->delete($this->admin(), $id, $this->clientIp());

        return ['success' => true];
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('risk.view')]
    #[GetMapping(path: 'maintenances')]
    public function maintenances(): array
    {
        return $this->maintenanceAdminService->list($this->request->all());
    }

    /**
     * 维护表单的下拉框（供应商、省份），有风控查看权限就能用。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('risk.view')]
    #[GetMapping(path: 'maintenances/options')]
    public function maintenanceOptions(): array
    {
        return ['suppliers' => $this->supplierAdminService->options(), 'provinces' => Province::NAMES];
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('risk.manage')]
    #[PostMapping(path: 'maintenances')]
    public function addMaintenance(): array
    {
        return $this->maintenanceAdminService->create($this->admin(), $this->request->all(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('risk.manage')]
    #[PostMapping(path: 'maintenances/{id:\d+}/finish')]
    public function finishMaintenance(int $id): array
    {
        return $this->maintenanceAdminService->finish($this->admin(), $id, $this->clientIp());
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
