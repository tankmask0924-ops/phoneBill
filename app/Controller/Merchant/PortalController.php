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

namespace App\Controller\Merchant;

use App\Controller\AbstractController;
use App\Middleware\MerchantAuthMiddleware;
use App\Model\Merchant;
use App\Model\MerchantUser;
use App\Network\ClientIpResolver;
use App\Service\MerchantPortal\MerchantAccountService;
use App\Service\MerchantPortal\MerchantPortalService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 商户后台：首页、订单记录、资金流水、账户（只读，规则见 MerchantPortalService）；
 * 自己改 IP 白名单、重置签名密钥（见 MerchantAccountService）。
 */
#[Controller(prefix: '/merchant')]
#[Middleware(MerchantAuthMiddleware::class)]
class PortalController extends AbstractController
{
    #[Inject]
    protected MerchantPortalService $portalService;

    #[Inject]
    protected MerchantAccountService $accountService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[GetMapping(path: 'dashboard')]
    public function dashboard(): array
    {
        return $this->portalService->dashboard($this->merchant());
    }

    #[GetMapping(path: 'orders')]
    public function orders(): array
    {
        return $this->portalService->orders($this->merchant(), $this->request->all());
    }

    #[GetMapping(path: 'order-filter-options')]
    public function orderFilterOptions(): array
    {
        return $this->portalService->orderFilterOptions($this->merchant());
    }

    #[GetMapping(path: 'orders/{id:\d+}')]
    public function order(int $id): array
    {
        return $this->portalService->order($this->merchant(), $id);
    }

    #[GetMapping(path: 'balance-logs')]
    public function balanceLogs(): array
    {
        return $this->portalService->balanceLogs($this->merchant(), $this->request->all());
    }

    #[GetMapping(path: 'products')]
    public function products(): array
    {
        return $this->portalService->products($this->merchant());
    }

    #[GetMapping(path: 'account')]
    public function account(): array
    {
        return $this->portalService->account($this->merchant());
    }

    #[PutMapping(path: 'account/ip-whitelist')]
    public function updateIpWhitelist(): array
    {
        return $this->accountService->updateIpWhitelist(
            $this->user(),
            $this->merchant(),
            (string) $this->request->input('password', ''),
            $this->request->input('ip_whitelist'),
            $this->clientIpResolver->resolve($this->request),
        );
    }

    #[PostMapping(path: 'account/reset-secret')]
    public function resetSecret(): array
    {
        return $this->accountService->resetSecret(
            $this->user(),
            $this->merchant(),
            (string) $this->request->input('password', ''),
            $this->clientIpResolver->resolve($this->request),
        );
    }

    private function user(): MerchantUser
    {
        /* @var MerchantUser */
        return $this->request->getAttribute('merchant_user');
    }

    private function merchant(): Merchant
    {
        /* @var Merchant */
        return $this->request->getAttribute('merchant');
    }
}
