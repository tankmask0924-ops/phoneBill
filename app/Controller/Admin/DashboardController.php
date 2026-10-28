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
use App\Service\Admin\DashboardAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 首页汇总，含销售额和利润，要有查看订单的权限；待办（待审核的付款账号、加款申请）要有查看加款申请的权限；
 * 订单待办（异常订单数）、经营统计（按日期范围汇总的成功金额和利润）要有查看订单的权限。
 */
#[Controller(prefix: '/admin/dashboard')]
class DashboardController extends AbstractController
{
    #[Inject]
    protected DashboardAdminService $dashboardAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->dashboardAdminService->summary();
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.view')]
    #[GetMapping(path: 'todos')]
    public function todos(): array
    {
        return $this->dashboardAdminService->todos();
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.view')]
    #[GetMapping(path: 'order-todos')]
    public function orderTodos(): array
    {
        return $this->dashboardAdminService->orderTodos();
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.view')]
    #[GetMapping(path: 'stats')]
    public function stats(): array
    {
        return $this->dashboardAdminService->stats($this->request->all());
    }
}
