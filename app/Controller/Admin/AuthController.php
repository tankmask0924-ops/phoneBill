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

use App\Controller\AbstractController;
use App\Middleware\AdminAuthMiddleware;
use App\Model\AdminUser;
use App\Service\Admin\AuthService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 管理后台账户：登录、当前管理员信息、修改自己的密码。
 *
 * 普通 JSON body，鉴权失败靠 HttpException 抛真实 HTTP 状态码（401/403/422）。
 * login 公开；me / password 只挂 AdminAuthMiddleware（任何登录管理员都能用，不需要权限编码）。
 * 管理员账号不能自助注册：第一个账号用 `admin:create` 命令建，之后在「管理员账号」页面建。
 */
#[Controller(prefix: '/admin/auth')]
class AuthController extends AbstractController
{
    #[Inject]
    protected AuthService $authService;

    #[PostMapping(path: 'login')]
    public function login(): array
    {
        $username = (string) $this->request->input('username', '');
        $password = (string) $this->request->input('password', '');

        return $this->authService->login($username, $password);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[GetMapping(path: 'me')]
    public function me(): array
    {
        return $this->authService->me($this->currentAdmin());
    }

    /**
     * 修改自己的密码，返回新 token。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[PutMapping(path: 'password')]
    public function changePassword(): array
    {
        return $this->authService->changePassword(
            $this->currentAdmin(),
            (string) $this->request->input('old_password', ''),
            (string) $this->request->input('new_password', ''),
        );
    }

    private function currentAdmin(): AdminUser
    {
        /* @var AdminUser */
        return $this->request->getAttribute('admin');
    }
}
