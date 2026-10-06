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
use App\Service\MerchantPortal\MerchantAuthService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 商户后台账户：登录、当前账号、修改自己的密码。账号由平台在「商户」页面开，不能自助注册。
 */
#[Controller(prefix: '/merchant/auth')]
class AuthController extends AbstractController
{
    #[Inject]
    protected MerchantAuthService $authService;

    #[PostMapping(path: 'login')]
    public function login(): array
    {
        return $this->authService->login((string) $this->request->input('username', ''), (string) $this->request->input('password', ''));
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

    private function user(): MerchantUser
    {
        /* @var MerchantUser */
        return $this->request->getAttribute('merchant_user');
    }
}
