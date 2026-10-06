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

namespace App\Middleware;

use App\Auth\AdminJwtGuard;
use App\Dao\AdminUserDao;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 管理后台登录态鉴权：校验 `Authorization: Bearer <JWT>`，通过后把 AdminUser 放进
 * request attribute `admin`。
 *
 * 只在需要登录态的方法上挂 `#[Middleware(AdminAuthMiddleware::class)]`（方法级注解）；
 * 不要用 `#[Controller(options: ['middleware' => [...]])]`，会被方法级中间件整体覆盖。
 *
 * 跟 AdminPermissionMiddleware 一起挂时必须写在它前面：同优先级的 #[Middleware]
 * 按书写顺序执行，先鉴权、放好 `admin`，权限中间件才能读到。
 */
class AdminAuthMiddleware implements MiddlewareInterface
{
    #[Inject]
    protected AdminJwtGuard $tokenGuard;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (! str_starts_with($header, 'Bearer ')) {
            throw new HttpException(401, '未登录或登录已过期');
        }

        $token = trim(substr($header, strlen('Bearer ')));
        if ($token === '') {
            throw new HttpException(401, '未登录或登录已过期');
        }

        $claims = $this->tokenGuard->resolve($token);
        if ($claims === null) {
            throw new HttpException(401, '未登录或登录已过期');
        }

        $adminUser = $this->adminUserDao->find($claims['id']);
        if (! $adminUser || $adminUser->status !== 'active') {
            throw new HttpException(401, '未登录或登录已过期');
        }

        // 改过密码（自己改或被其他管理员重置）之后，之前签发的 token 一律失效
        if (! hash_equals($this->tokenGuard->passwordVersion($adminUser->password), $claims['pv'])) {
            throw new HttpException(401, '密码已修改，请重新登录');
        }

        return $handler->handle($request->withAttribute('admin', $adminUser));
    }
}
