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

namespace App\Service\Order;

use App\Dao\MerchantDao;
use App\Dao\OrderDao;
use App\Dao\OrderNotifyLogDao;
use App\Model\Order;
use App\Network\HttpClient;
use App\Security\Encrypter;
use App\Security\OpenApiSigner;
use App\Service\AbstractService;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use Throwable;

/**
 * 订单出结果后通知商户：POST JSON 到通知地址（下单时传的优先，否则用商户配置的），带签名。
 *
 * 商户返回 HTTP 200 且响应体是 success（不区分大小写）才算送达，否则按 order.notify_retry_delays 的间隔重试，用完放弃。
 * 通知内容总是订单当前的状态，所以冲正后重发通知也会带上最新结果。
 */
class MerchantNotifyService extends AbstractService
{
    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected OrderNotifyLogDao $notifyLogDao;

    #[Inject]
    protected OrderService $orderService;

    #[Inject]
    protected OrderDispatcher $dispatcher;

    #[Inject]
    protected HttpClient $httpClient;

    #[Inject]
    protected OpenApiSigner $signer;

    #[Inject]
    protected Encrypter $encrypter;

    #[Inject]
    protected ConfigInterface $config;

    /**
     * @param int $attemptNo 第几次通知，从 1 开始
     */
    public function notify(int $orderId, int $attemptNo): void
    {
        $order = $this->orderDao->find($orderId);
        if ($order === null || ! $order->isFinal()) {
            return;
        }
        $merchant = $this->merchantDao->find($order->merchant_id);
        $url = $order->notify_url ?: $merchant?->notify_url;
        if ($merchant === null || ! $url) {
            $this->orderDao->update($order->id, ['notify_status' => Order::NOTIFY_NONE]);

            return;
        }

        $payload = $this->orderService->present($order) + [
            'app_key' => $merchant->app_key,
            'timestamp' => time(),
            'nonce' => bin2hex(random_bytes(8)),
        ];
        $payload['sign'] = $this->signer->sign($payload, $this->encrypter->decrypt($merchant->app_secret));

        $status = null;
        try {
            $response = $this->httpClient->postJson($url, $payload);
            $status = $response['status'];
            $body = $response['body'];
        } catch (Throwable $e) {
            $body = '请求失败：' . $e->getMessage();
        }
        $success = $status === 200 && strtolower(trim($body)) === 'success';

        $this->notifyLogDao->create([
            'order_id' => $order->id,
            'attempt_no' => $attemptNo,
            'url' => $url,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'http_status' => $status,
            'response' => mb_substr($body, 0, 2000),
            'success' => $success,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($success) {
            $this->orderDao->update($order->id, ['notify_status' => Order::NOTIFY_SUCCESS, 'notified_at' => date('Y-m-d H:i:s')]);

            return;
        }
        $delays = (array) $this->config->get('order.notify_retry_delays', []);
        if ($attemptNo <= count($delays)) {
            $this->orderDao->update($order->id, ['notify_status' => Order::NOTIFY_RETRYING]);
            $this->dispatcher->notify($order->id, $attemptNo + 1, (int) $delays[$attemptNo - 1]);
        } else {
            $this->orderDao->update($order->id, ['notify_status' => Order::NOTIFY_FAILED]);
        }
    }
}
