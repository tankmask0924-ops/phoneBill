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

namespace App\Network;

use Closure;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Guzzle\ClientFactory;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 对外发 HTTP 请求（通知商户、调供应商接口）。单独包一层，测试里替换成 mock，不发真实请求。
 *
 * 每次请求后在当前协程里记下 HTTP 状态码（没拿到响应记 0），SupplierGateway 用它判断这次调供应商
 * 接口通不通（查单记录里显示「接口正常 / 接口报错 / 接口不通」）。
 */
class HttpClient
{
    private const CONTEXT_LAST_STATUS = 'http_client.last_status';

    #[Inject]
    protected ClientFactory $clientFactory;

    /**
     * 当前协程最后一次请求的 HTTP 状态码：0 = 没拿到响应（连不上、超时），null = 从上次 forgetLastStatus() 起没发过请求。
     */
    public function lastStatus(): ?int
    {
        return Context::get(self::CONTEXT_LAST_STATUS);
    }

    public function forgetLastStatus(): void
    {
        Context::destroy(self::CONTEXT_LAST_STATUS);
    }

    /**
     * 网络错误直接抛异常；HTTP 非 2xx 不抛，照常返回状态码和响应体。
     *
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string}
     */
    public function postJson(string $url, array $payload, float $timeoutSeconds = 5.0): array
    {
        $response = $this->track(fn () => $this->clientFactory->create(['timeout' => $timeoutSeconds, 'http_errors' => false])
            ->post($url, ['json' => $payload]));

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }

    /**
     * 参数放查询字符串的 GET，异常约定同 postJson。
     *
     * @param array<string, string> $query
     * @return array{status: int, body: string}
     */
    public function get(string $url, array $query, float $timeoutSeconds = 5.0): array
    {
        $response = $this->track(fn () => $this->clientFactory->create(['timeout' => $timeoutSeconds, 'connect_timeout' => 3.0, 'http_errors' => false])
            ->get($url, ['query' => $query]));

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }

    /**
     * application/x-www-form-urlencoded 表单 POST，异常约定同 postJson。
     *
     * @param array<string, string> $form
     * @return array{status: int, body: string}
     */
    public function postForm(string $url, array $form, float $timeoutSeconds = 5.0): array
    {
        $response = $this->track(fn () => $this->clientFactory->create(['timeout' => $timeoutSeconds, 'connect_timeout' => 3.0, 'http_errors' => false])
            ->post($url, ['form_params' => $form]));

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }

    /**
     * 原样发送请求体的 POST，用于签名算在请求体字符串上的接口，异常约定同 postJson。
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function postRaw(string $url, string $body, array $headers, float $timeoutSeconds = 5.0): array
    {
        $response = $this->track(fn () => $this->clientFactory->create(['timeout' => $timeoutSeconds, 'connect_timeout' => 3.0, 'http_errors' => false])
            ->post($url, ['body' => $body, 'headers' => $headers]));

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }

    /**
     * 任意方法、原样请求体（对象存储上传 / 删除用），异常约定同 postJson。
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function send(string $method, string $url, array $headers, string $body = '', float $timeoutSeconds = 10.0): array
    {
        $response = $this->track(fn () => $this->clientFactory->create(['timeout' => $timeoutSeconds, 'connect_timeout' => 3.0, 'http_errors' => false])
            ->request($method, $url, ['body' => $body, 'headers' => $headers]));

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }

    /**
     * 发请求并记下状态码；网络错误记 0 后原样抛出。
     *
     * @param Closure(): ResponseInterface $send
     */
    protected function track(Closure $send): ResponseInterface
    {
        try {
            $response = $send();
        } catch (Throwable $e) {
            $this->rememberStatus(0);
            throw $e;
        }
        $this->rememberStatus($response->getStatusCode());

        return $response;
    }

    protected function rememberStatus(int $status): void
    {
        Context::set(self::CONTEXT_LAST_STATUS, $status);
    }
}
