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

namespace HyperfTest\Support;

use App\Network\HttpClient;

/**
 * 不发真实请求：记下请求，按 $responses 依次返回（用完后一直返回最后一个）。
 */
class FakeHttpClient extends HttpClient
{
    /** @var list<array{method: string, url: string, payload: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array{status: int, body: string}> */
    public array $responses = [['status' => 200, 'body' => 'success']];

    public function postJson(string $url, array $payload, float $timeoutSeconds = 5.0): array
    {
        return $this->record('POST_JSON', $url, $payload);
    }

    public function get(string $url, array $query, float $timeoutSeconds = 5.0): array
    {
        return $this->record('GET', $url, $query);
    }

    public function postForm(string $url, array $form, float $timeoutSeconds = 5.0): array
    {
        return $this->record('POST_FORM', $url, $form);
    }

    public function postRaw(string $url, string $body, array $headers, float $timeoutSeconds = 5.0): array
    {
        return $this->record('POST_RAW', $url, ['body' => $body, 'headers' => $headers]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string}
     */
    private function record(string $method, string $url, array $payload): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'payload' => $payload];

        return count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
    }
}
