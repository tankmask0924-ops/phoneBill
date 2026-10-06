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
use App\Service\MerchantPortal\MerchantPortalService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 商户后台（只读）：首页、订单记录、资金流水、账户，规则见 MerchantPortalService。
 */
#[Controller(prefix: '/merchant')]
#[Middleware(MerchantAuthMiddleware::class)]
class PortalController extends AbstractController
{
    #[Inject]
    protected MerchantPortalService $portalService;

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

    #[GetMapping(path: 'account')]
    public function account(): array
    {
        return $this->portalService->account($this->merchant());
    }

    private function merchant(): Merchant
    {
        /* @var Merchant */
        return $this->request->getAttribute('merchant');
    }
}
