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
use App\Service\MerchantPortal\MerchantBatchOrderService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 商户后台 - 批量下单：预览、提交，规则见 MerchantBatchOrderService。
 */
#[Controller(prefix: '/merchant/batch-orders')]
#[Middleware(MerchantAuthMiddleware::class)]
class BatchOrderController extends AbstractController
{
    #[Inject]
    protected MerchantBatchOrderService $batchOrderService;

    #[PostMapping(path: 'preview')]
    public function preview(): array
    {
        return $this->batchOrderService->preview($this->merchant(), $this->request->all());
    }

    #[PostMapping(path: '')]
    public function submit(): array
    {
        return $this->batchOrderService->submit($this->merchant(), $this->user(), $this->request->all());
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
