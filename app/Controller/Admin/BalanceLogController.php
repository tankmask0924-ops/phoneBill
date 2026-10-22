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
use Psr\Http\Message\ResponseInterface;

/**
 * 商户中心 - 资金流水，列表和导出。
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

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.view')]
    #[GetMapping(path: 'export')]
    public function export(): ResponseInterface
    {
        $csv = $this->balanceLogAdminService->exportCsv($this->request->all());

        return $this->response->raw($csv)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="balance-logs-' . date('YmdHis') . '.csv"');
    }
}
