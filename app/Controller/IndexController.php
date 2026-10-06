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

namespace App\Controller;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;

/**
 * 健康检查：负载均衡 / 部署脚本探活用。
 */
#[Controller(prefix: '/')]
class IndexController extends AbstractController
{
    #[Inject]
    protected ConfigInterface $config;

    #[GetMapping(path: '')]
    public function index(): array
    {
        return [
            'app' => $this->config->get('app_name'),
            'status' => 'ok',
        ];
    }
}
