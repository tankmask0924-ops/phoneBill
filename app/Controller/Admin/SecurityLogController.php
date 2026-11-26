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
use App\Service\Admin\SecurityLogAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 系统设置 - 登录日志，和操作日志共用查看权限 operation_log.view。
 */
#[Controller(prefix: '/admin/security-logs')]
class SecurityLogController extends AbstractController
{
    #[Inject]
    protected SecurityLogAdminService $securityLogAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('operation_log.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->securityLogAdminService->list($this->request->all());
    }
}
