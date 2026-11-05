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
use App\Service\Setting\SiteSettingService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;

/**
 * 商户后台的公开信息：系统名称跟管理后台「系统配置 - 基本设置」一致。不用登录：登录页也要显示。
 */
#[Controller(prefix: '/merchant')]
class SiteController extends AbstractController
{
    #[Inject]
    protected SiteSettingService $siteSettingService;

    /**
     * 空字符串表示没设置，前端用打包时的默认名称。
     */
    #[GetMapping(path: 'site')]
    public function site(): array
    {
        return ['site_name' => $this->siteSettingService->name()];
    }
}
