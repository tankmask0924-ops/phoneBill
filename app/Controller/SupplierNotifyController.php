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

use App\Service\Order\SupplierCallbackService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Psr\Http\Message\ResponseInterface;

/**
 * 供应商结果回调入口，地址配给供应商：https://你的域名/notify/supplier/{供应商编码}.
 */
#[Controller(prefix: '/notify')]
class SupplierNotifyController extends AbstractController
{
    #[Inject]
    protected SupplierCallbackService $callbackService;

    #[RequestMapping(path: 'supplier/{code}', methods: ['GET', 'POST'])]
    public function supplier(string $code): ResponseInterface
    {
        $result = $this->callbackService->handle($code, $this->request);

        return $this->response->raw($result['body'])->withStatus($result['status']);
    }
}
