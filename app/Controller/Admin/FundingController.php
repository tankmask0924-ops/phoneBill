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
use App\Service\Admin\FundingAdminService;
use App\Service\Admin\MerchantAdminService;
use App\Service\Admin\OnlinePaymentAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 商户中心 - 加款申请、付款账号审核（规则见 FundingAdminService）、在线支付（支付宝扫码转账，见 OnlinePaymentAdminService）。
 */
#[Controller(prefix: '/admin/funding')]
class FundingController extends AbstractController
{
    #[Inject]
    protected FundingAdminService $fundingService;

    #[Inject]
    protected MerchantAdminService $merchantAdminService;

    #[Inject]
    protected OnlinePaymentAdminService $onlinePaymentService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.view')]
    #[GetMapping(path: 'merchant-options')]
    public function merchantOptions(): array
    {
        return $this->merchantAdminService->options();
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.view')]
    #[GetMapping(path: 'requests')]
    public function requests(): array
    {
        return $this->fundingService->requests($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.view')]
    #[GetMapping(path: 'requests/{id:\d+}')]
    public function requestDetail(int $id): array
    {
        return $this->fundingService->requestDetail($id);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.review')]
    #[PostMapping(path: 'requests/{id:\d+}/approve')]
    public function approveRequest(int $id): array
    {
        return $this->fundingService->approveRequest($this->admin(), $id, $this->request->input('remark'), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.review')]
    #[PostMapping(path: 'requests/{id:\d+}/reject')]
    public function rejectRequest(int $id): array
    {
        return $this->fundingService->rejectRequest($this->admin(), $id, $this->request->input('remark'), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.view')]
    #[GetMapping(path: 'payer-accounts')]
    public function payerAccounts(): array
    {
        return $this->fundingService->payerAccounts($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.review')]
    #[PostMapping(path: 'payer-accounts/{id:\d+}/approve')]
    public function approvePayer(int $id): array
    {
        return $this->fundingService->approvePayer($this->admin(), $id, $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.review')]
    #[PostMapping(path: 'payer-accounts/{id:\d+}/reject')]
    public function rejectPayer(int $id): array
    {
        return $this->fundingService->rejectPayer($this->admin(), $id, $this->request->input('reason'), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.view')]
    #[GetMapping(path: 'online-payments')]
    public function onlinePayments(): array
    {
        return $this->onlinePaymentService->list($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.view')]
    #[GetMapping(path: 'online-payments/{id:\d+}')]
    public function onlinePayment(int $id): array
    {
        return $this->onlinePaymentService->detail($id);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.review')]
    #[PostMapping(path: 'online-payments/{id:\d+}/candidates')]
    public function onlinePaymentCandidates(int $id): array
    {
        return $this->onlinePaymentService->candidates($id);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.review')]
    #[PostMapping(path: 'online-payments/{id:\d+}/confirm')]
    public function confirmOnlinePayment(int $id): array
    {
        return $this->onlinePaymentService->confirm($this->admin(), $id, $this->request->input('account_log_id'), $this->request->input('remark'), $this->clientIp());
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
