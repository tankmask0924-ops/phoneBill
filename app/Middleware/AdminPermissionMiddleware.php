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

use App\Annotation\RequiresPermission;
use App\Dao\AdminRolePermissionDao;
use App\Model\AdminUser;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpServer\Router\Dispatched;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionMethod;

/**
 * 按 Controller 方法上的 #[RequiresPermission] 校验当前管理员的角色是否拥有该权限编码，
 * 没有则 403。必须排在 AdminAuthMiddleware 之后（依赖它放进 request 的 `admin`）。
 *
 * 用反射从 Dispatched::$handler->callback（[ControllerClass, method]）取出匹配到的方法，
 * 读它身上的 #[RequiresPermission]；没有这个注解的方法视为「登录即可访问」，直接放行。
 */
class AdminPermissionMiddleware implements MiddlewareInterface
{
    #[Inject]
    protected AdminRolePermissionDao $adminRolePermissionDao;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requiredCode = $this->resolveRequiredPermissionCode($request);
        if ($requiredCode !== null) {
            /** @var AdminUser $admin */
            $admin = $request->getAttribute('admin');

            if (! $this->adminRolePermissionDao->roleHasPermission($admin->role_id, $requiredCode)) {
                throw new HttpException(403, '无权限访问');
            }
        }

        return $handler->handle($request);
    }

    private function resolveRequiredPermissionCode(ServerRequestInterface $request): ?string
    {
        $dispatched = $request->getAttribute(Dispatched::class);
        if (! $dispatched instanceof Dispatched || ! $dispatched->handler) {
            return null;
        }

        $callback = $dispatched->handler->callback;
        if (! is_array($callback) || count($callback) !== 2) {
            return null;
        }

        [$class, $method] = $callback;
        if (! is_string($class) || ! is_string($method) || ! method_exists($class, $method)) {
            return null;
        }

        $attributes = (new ReflectionMethod($class, $method))->getAttributes(RequiresPermission::class);
        if ($attributes === []) {
            return null;
        }

        /** @var RequiresPermission $requiresPermission */
        $requiresPermission = $attributes[0]->newInstance();

        return $requiresPermission->code;
    }
}
