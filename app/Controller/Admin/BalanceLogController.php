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
use App\Service\Admin\BalanceLogAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 商户中心 - 资金流水。
 */
#[Controller(prefix: '/admin/balance-logs')]
class BalanceLogController extends AbstractController
{
    #[Inject]
    protected BalanceLogAdminService $balanceLogAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->balanceLogAdminService->list($this->request->all());
    }
}
