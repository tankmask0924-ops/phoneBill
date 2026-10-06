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

namespace App\Controller\OpenApi;

use App\Controller\AbstractController;
use App\Dao\MerchantDao;
use App\Dao\OrderDao;
use App\Exception\OpenApiException;
use App\Middleware\OpenApiAuthMiddleware;
use App\Model\Merchant;
use App\Service\Merchant\MerchantCatalogService;
use App\Service\Order\OrderService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 开放接口（商户系统调用），接入说明见 docs/api.md。成功返回 {code: "OK", message, data}，失败见 OpenApiException。
 */
#[Controller(prefix: '/open/v1')]
#[Middleware(OpenApiAuthMiddleware::class)]
class OpenApiController extends AbstractController
{
    #[Inject]
    protected OrderService $orderService;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantCatalogService $catalogService;

    /**
     * 下单。
     */
    #[PostMapping(path: 'recharge')]
    public function recharge(): array
    {
        return $this->ok($this->orderService->present($this->orderService->create($this->merchant(), $this->params())));
    }

    /**
     * 查单：order_no 或 merchant_order_no 二选一。
     */
    #[GetMapping(path: 'order')]
    public function order(): array
    {
        $merchant = $this->merchant();
        $orderNo = (string) ($this->params()['order_no'] ?? '');
        $merchantOrderNo = (string) ($this->params()['merchant_order_no'] ?? '');
        $order = match (true) {
            $orderNo !== '' => $this->orderDao->findByOrderNo($orderNo),
            $merchantOrderNo !== '' => $this->orderDao->findByMerchantOrderNo($merchant->id, $merchantOrderNo),
            default => throw new OpenApiException(OpenApiException::INVALID_PARAMS, 'order_no 和 merchant_order_no 至少传一个'),
        };
        if ($order === null || $order->merchant_id !== $merchant->id) {
            throw new OpenApiException(OpenApiException::ORDER_NOT_FOUND, '订单不存在');
        }

        return $this->ok($this->orderService->present($order));
    }

    #[GetMapping(path: 'balance')]
    public function balance(): array
    {
        // 中间件里的商户来自缓存、不带余额，这里查最新的
        return $this->ok(['balance' => $this->merchantDao->find($this->merchant()->id)?->balance]);
    }

    /**
     * 已开通的商品和各运营商价格。
     */
    #[GetMapping(path: 'products')]
    public function products(): array
    {
        return $this->ok($this->catalogService->openedProducts($this->merchant()));
    }

    private function merchant(): Merchant
    {
        /* @var Merchant */
        return $this->request->getAttribute('merchant');
    }

    /**
     * 验过签的参数（见 OpenApiAuthMiddleware）。
     *
     * @return array<string, mixed>
     */
    private function params(): array
    {
        return (array) $this->request->getAttribute('open_api_params', []);
    }

    /**
     * @return array{code: string, message: string, data: mixed}
     */
    private function ok(mixed $data): array
    {
        return ['code' => 'OK', 'message' => '成功', 'data' => $data];
    }
}
