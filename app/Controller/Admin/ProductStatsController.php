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
use App\Service\Admin\ProductStatsAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 商品中心 - 商品统计（每日成功率、耗时；按省份、供应商的订单统计），只读。
 */
#[Controller(prefix: '/admin/product-stats')]
class ProductStatsController extends AbstractController
{
    #[Inject]
    protected ProductStatsAdminService $statsAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->statsAdminService->list($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    // 有成功金额和利润，跟订单列表、首页汇总一样要 order.view
    #[RequiresPermission('order.view')]
    #[GetMapping(path: 'provinces')]
    public function provinces(): array
    {
        return $this->statsAdminService->provinces($this->request->all());
    }
}
