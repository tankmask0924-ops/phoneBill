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
use App\Service\Admin\SupplierAdminService;
use App\Service\Admin\SupplierProductAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 商品中心 - 供应商商品，规则见 SupplierProductAdminService。
 */
#[Controller(prefix: '/admin/supplier-products')]
class SupplierProductController extends AbstractController
{
    #[Inject]
    protected SupplierProductAdminService $supplierProductAdminService;

    #[Inject]
    protected SupplierAdminService $supplierAdminService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier_product.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->supplierProductAdminService->list($this->request->all());
    }

    /**
     * 表单选项：省份（覆盖省份在供应商商品上）。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier_product.view')]
    #[GetMapping(path: 'meta')]
    public function meta(): array
    {
        return ['provinces' => Province::NAMES];
    }

    /**
     * 供应商下拉框，有供应商商品查看权限就能用，不要求供应商查看权限。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier_product.view')]
    #[GetMapping(path: 'supplier-options')]
    public function supplierOptions(): array
    {
        return $this->supplierAdminService->options();
    }

    /**
     * 实时查供应商的可售商品（驱动支持时），建供应商商品时从里面选。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier_product.manage')]
    #[GetMapping(path: 'upstream-products')]
    public function upstreamProducts(): array
    {
        return $this->supplierProductAdminService->upstreamProducts($this->request->input('supplier_id'));
    }

    /**
     * 同步成本价预览：按上游商品列表算出这个供应商名下哪些成本价会变，不保存。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier_product.manage')]
    #[GetMapping(path: 'sync-cost')]
    public function syncCostPreview(): array
    {
        return $this->supplierProductAdminService->syncCostPrices($this->admin(), $this->request->input('supplier_id'), false, $this->clientIp());
    }

    /**
     * 按上游商品列表同步这个供应商名下供应商商品的成本价。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier_product.manage')]
    #[PostMapping(path: 'sync-cost')]
    public function syncCost(): array
    {
        return $this->supplierProductAdminService->syncCostPrices($this->admin(), $this->request->input('supplier_id'), true, $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier_product.manage')]
    #[PostMapping(path: '')]
    public function store(): array
    {
        return $this->supplierProductAdminService->create($this->admin(), $this->request->all(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier_product.manage')]
    #[PutMapping(path: '{id:\d+}')]
    public function update(int $id): array
    {
        return $this->supplierProductAdminService->update($this->admin(), $id, $this->request->all(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier_product.manage')]
    #[PostMapping(path: '{id:\d+}/status')]
    public function changeStatus(int $id): array
    {
        return $this->supplierProductAdminService->changeStatus($this->admin(), $id, $this->request->input('status'), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier_product.manage')]
    #[DeleteMapping(path: '{id:\d+}')]
    public function destroy(int $id): array
    {
        $this->supplierProductAdminService->delete($this->admin(), $id, $this->clientIp());

        return ['success' => true];
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier_product.view')]
    #[GetMapping(path: '{id:\d+}/bindings')]
    public function bindings(int $id): array
    {
        return $this->supplierProductAdminService->bindings($id);
    }

    /**
     * 一键解绑：改的是平台商品的绑定，所以要 product.manage。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product.manage')]
    #[DeleteMapping(path: '{id:\d+}/bindings')]
    public function unbind(int $id): array
    {
        return ['unbound' => $this->supplierProductAdminService->unbindAll($this->admin(), $id, $this->clientIp())];
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
