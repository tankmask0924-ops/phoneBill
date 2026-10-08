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

use Hyperf\Di\Annotation\Inject;
use Hyperf\Guzzle\ClientFactory;

/**
 * 对外发 HTTP 请求（通知商户、调供应商接口）。单独包一层，测试里替换成 mock，不发真实请求。
 */
class HttpClient
{
    #[Inject]
    protected ClientFactory $clientFactory;

    /**
     * 网络错误直接抛异常；HTTP 非 2xx 不抛，照常返回状态码和响应体。
     *
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string}
     */
    public function postJson(string $url, array $payload, float $timeoutSeconds = 5.0): array
    {
        $response = $this->clientFactory->create(['timeout' => $timeoutSeconds, 'http_errors' => false])
            ->post($url, ['json' => $payload]);

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
        $response = $this->clientFactory->create(['timeout' => $timeoutSeconds, 'connect_timeout' => 3.0, 'http_errors' => false])
            ->get($url, ['query' => $query]);

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
        $response = $this->clientFactory->create(['timeout' => $timeoutSeconds, 'connect_timeout' => 3.0, 'http_errors' => false])
            ->post($url, ['form_params' => $form]);

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
        $response = $this->clientFactory->create(['timeout' => $timeoutSeconds, 'connect_timeout' => 3.0, 'http_errors' => false])
            ->post($url, ['body' => $body, 'headers' => $headers]);

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }
}
