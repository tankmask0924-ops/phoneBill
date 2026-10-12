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
use App\Service\MerchantPortal\MerchantFundingService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 商户后台 - 加款：付款账号、收款账户、凭证上传、加款申请，规则见 MerchantFundingService。
 */
#[Controller(prefix: '/merchant')]
#[Middleware(MerchantAuthMiddleware::class)]
class FundingController extends AbstractController
{
    #[Inject]
    protected MerchantFundingService $fundingService;

    #[GetMapping(path: 'payer-accounts')]
    public function payerAccounts(): array
    {
        return $this->fundingService->payerAccounts($this->merchant());
    }

    #[PostMapping(path: 'payer-accounts')]
    public function createPayer(): array
    {
        return $this->fundingService->createPayer($this->merchant(), $this->user(), $this->request->all());
    }

    #[PutMapping(path: 'payer-accounts/{id:\d+}')]
    public function updatePayer(int $id): array
    {
        return $this->fundingService->updatePayer($this->merchant(), $this->user(), $id, $this->request->all());
    }

    #[DeleteMapping(path: 'payer-accounts/{id:\d+}')]
    public function deletePayer(int $id): array
    {
        $this->fundingService->deletePayer($this->merchant(), $this->user(), $id);

        return ['success' => true];
    }

    #[GetMapping(path: 'receiving-accounts')]
    public function receivingAccounts(): array
    {
        return $this->fundingService->receivingAccounts($this->merchant());
    }

    #[PostMapping(path: 'funding-vouchers')]
    public function uploadVoucher(): array
    {
        return $this->fundingService->uploadVoucher($this->merchant(), $this->request->file('file'));
    }

    #[GetMapping(path: 'funding-requests')]
    public function requests(): array
    {
        return $this->fundingService->requests($this->merchant(), $this->request->all());
    }

    #[GetMapping(path: 'funding-requests/{id:\d+}')]
    public function requestDetail(int $id): array
    {
        return $this->fundingService->request($this->merchant(), $id);
    }

    #[PostMapping(path: 'funding-requests')]
    public function createRequest(): array
    {
        return $this->fundingService->createRequest($this->merchant(), $this->user(), $this->request->all());
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
