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
use App\Service\MerchantPortal\MerchantOnlinePaymentService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 商户后台 - 支付宝扫码转账加款，规则见 MerchantOnlinePaymentService。
 */
#[Controller(prefix: '/merchant/online-payments')]
#[Middleware(MerchantAuthMiddleware::class)]
class OnlinePaymentController extends AbstractController
{
    #[Inject]
    protected MerchantOnlinePaymentService $paymentService;

    #[GetMapping(path: 'options')]
    public function options(): array
    {
        return $this->paymentService->options($this->merchant());
    }

    #[GetMapping(path: '')]
    public function list(): array
    {
        return $this->paymentService->list($this->merchant(), $this->request->all());
    }

    #[PostMapping(path: '')]
    public function create(): array
    {
        return $this->paymentService->create($this->merchant(), $this->user(), $this->request->all());
    }

    #[GetMapping(path: '{id:\d+}')]
    public function detail(int $id): array
    {
        return $this->paymentService->detail($this->merchant(), $id);
    }

    #[PostMapping(path: '{id:\d+}/check')]
    public function check(int $id): array
    {
        return $this->paymentService->check($this->merchant(), $id);
    }

    #[PostMapping(path: '{id:\d+}/cancel')]
    public function cancel(int $id): array
    {
        return $this->paymentService->cancel($this->merchant(), $this->user(), $id);
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
