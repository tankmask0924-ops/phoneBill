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
use App\Model\MerchantUser;
use App\Network\ClientIpResolver;
use App\Service\MerchantPortal\MerchantAuthService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 商户后台账户：登录（开启了登录短信验证要再验证短信，见 MerchantAuthService）、当前账号、修改自己的密码、绑定 / 修改手机、开关登录短信验证。
 * 账号由平台在「商户」页面开，不能自助注册。
 */
#[Controller(prefix: '/merchant/auth')]
class AuthController extends AbstractController
{
    #[Inject]
    protected MerchantAuthService $authService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[PostMapping(path: 'login')]
    public function login(): array
    {
        return $this->authService->login((string) $this->request->input('username', ''), (string) $this->request->input('password', ''));
    }

    #[PostMapping(path: 'login/sms-code')]
    public function resendLoginCode(): array
    {
        return $this->authService->resendLoginCode((string) $this->request->input('ticket', ''));
    }

    #[PostMapping(path: 'login/verify')]
    public function verifyLogin(): array
    {
        return $this->authService->verifyLogin((string) $this->request->input('ticket', ''), (string) $this->request->input('code', ''));
    }

    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: 'me')]
    public function me(): array
    {
        return $this->authService->me($this->user(), $this->request->getAttribute('merchant'));
    }

    #[Middleware(MerchantAuthMiddleware::class)]
    #[PutMapping(path: 'password')]
    public function changePassword(): array
    {
        return $this->authService->changePassword(
            $this->user(),
            (string) $this->request->input('old_password', ''),
            (string) $this->request->input('new_password', ''),
        );
    }

    #[Middleware(MerchantAuthMiddleware::class)]
    #[PostMapping(path: 'mobile/code')]
    public function sendBindCode(): array
    {
        $this->authService->sendBindCode($this->user(), $this->request->input('mobile'));

        return ['success' => true];
    }

    #[Middleware(MerchantAuthMiddleware::class)]
    #[PutMapping(path: 'mobile')]
    public function bindMobile(): array
    {
        return $this->authService->bindMobile(
            $this->user(),
            (string) $this->request->input('password', ''),
            $this->request->input('mobile'),
            (string) $this->request->input('code', ''),
            $this->clientIpResolver->resolve($this->request),
        );
    }

    #[Middleware(MerchantAuthMiddleware::class)]
    #[PutMapping(path: 'sms-login')]
    public function setSmsLogin(): array
    {
        return $this->authService->setSmsLogin(
            $this->user(),
            $this->request->input('enabled'),
            (string) $this->request->input('password', ''),
            $this->clientIpResolver->resolve($this->request),
        );
    }

    private function user(): MerchantUser
    {
        /* @var MerchantUser */
        return $this->request->getAttribute('merchant_user');
    }
}
