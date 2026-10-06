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

namespace App\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * 标在管理后台 Controller 方法上，声明该动作需要的权限编码（admin_permissions.code，如 'admin_user.view'）。
 *
 * App\Middleware\AdminPermissionMiddleware 在请求分发时读取它做权限校验；
 * App\Aspect\AdminOperationLogAspect 按它切入，给写操作自动记操作日志。
 * 新增编码后必须同步加进 App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RequiresPermission extends AbstractAnnotation
{
    public function __construct(public string $code)
    {
    }
}
