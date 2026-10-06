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
use App\Service\Admin\OrderAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;

/**
 * 订单中心，规则见 OrderAdminService。
 */
#[Controller(prefix: '/admin/orders')]
class OrderController extends AbstractController
{
    #[Inject]
    protected OrderAdminService $orderAdminService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->orderAdminService->list($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.view')]
    #[GetMapping(path: 'filter-options')]
    public function filterOptions(): array
    {
        return $this->orderAdminService->filterOptions();
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.view')]
    #[GetMapping(path: 'export')]
    public function export(): ResponseInterface
    {
        $csv = $this->orderAdminService->exportCsv($this->request->all());

        return $this->response->raw($csv)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="orders-' . date('YmdHis') . '.csv"');
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.view')]
    #[GetMapping(path: '{id:\d+}')]
    public function show(int $id): array
    {
        return $this->orderAdminService->detail($id);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.manage')]
    #[PostMapping(path: '{id:\d+}/query')]
    public function query(int $id): array
    {
        return $this->orderAdminService->query($this->admin(), $id, $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.manage')]
    #[PostMapping(path: '{id:\d+}/success')]
    public function confirmSuccess(int $id): array
    {
        return $this->orderAdminService->confirmSuccess($this->admin(), $id, $this->request->input('remark'), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.manage')]
    #[PostMapping(path: '{id:\d+}/fail')]
    public function confirmFailed(int $id): array
    {
        return $this->orderAdminService->confirmFailed($this->admin(), $id, $this->request->input('remark'), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.reverse')]
    #[PostMapping(path: '{id:\d+}/reverse')]
    public function reverse(int $id): array
    {
        return $this->orderAdminService->reverse($this->admin(), $id, $this->request->input('remark'), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.manage')]
    #[PostMapping(path: '{id:\d+}/notify')]
    public function resendNotify(int $id): array
    {
        return $this->orderAdminService->resendNotify($this->admin(), $id, $this->clientIp());
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
