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

namespace App\Storage;

use App\Network\HttpClient;
use RuntimeException;

/**
 * 腾讯云对象存储 COS 的最小客户端：上传、删除、生成临时访问链接。不依赖 SDK，按官方「请求签名」文档自己签名：.
 *
 * - KeyTime = 开始;结束（秒级时间戳），SignKey = HMAC-SHA1(SecretKey, KeyTime)；
 * - HttpString = 小写方法\n路径\n参数串\n头部串\n（参数、头部的键都小写、URL 编码后按键排序）；
 * - StringToSign = sha1\nKeyTime\nsha1(HttpString)\n，Signature = HMAC-SHA1(SignKey, StringToSign)；
 * - 上传、删除把签名放在 Authorization 头里，临时链接把同样的字段放在查询字符串里。
 *
 * 存储桶建议设为私有读写，图片只通过临时链接查看。
 */
class CosClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $secretId,
        private readonly string $secretKey,
        /** 存储桶名，带 APPID，如 examplebucket-1250000000 */
        private readonly string $bucket,
        /** 地域，如 ap-guangzhou */
        private readonly string $region,
    ) {
    }

    public function host(): string
    {
        return "{$this->bucket}.cos.{$this->region}.myqcloud.com";
    }

    /**
     * 上传对象，失败抛异常。
     */
    public function put(string $key, string $body, string $contentType): void
    {
        $headers = ['Host' => $this->host(), 'Content-Type' => $contentType];
        $headers['Authorization'] = $this->authorization('PUT', $this->signPath($key), ['host' => $this->host()], [], time(), 600);
        $response = $this->http->send('PUT', $this->url($key), $headers, $body, 30.0);
        if ($response['status'] !== 200) {
            throw new RuntimeException('上传到腾讯云 COS 失败：HTTP ' . $response['status'] . ' ' . $this->errorMessage($response['body']));
        }
    }

    /**
     * 删除对象，对象不存在也算成功（COS 返回 204）。
     */
    public function delete(string $key): void
    {
        $headers = ['Host' => $this->host()];
        $headers['Authorization'] = $this->authorization('DELETE', $this->signPath($key), ['host' => $this->host()], [], time(), 600);
        $response = $this->http->send('DELETE', $this->url($key), $headers);
        if (! in_array($response['status'], [200, 204], true)) {
            throw new RuntimeException('删除腾讯云 COS 对象失败：HTTP ' . $response['status'] . ' ' . $this->errorMessage($response['body']));
        }
    }

    /**
     * 私有对象的临时访问链接（GET），$ttlSeconds 秒内有效。
     */
    public function presignedUrl(string $key, int $ttlSeconds = 600, ?int $now = null): string
    {
        $auth = $this->authorization('GET', $this->signPath($key), ['host' => $this->host()], [], $now ?? time(), $ttlSeconds);
        $query = [];
        foreach (explode('&', $auth) as $pair) {
            [$k, $v] = explode('=', $pair, 2);
            $query[] = $k . '=' . rawurlencode($v);
        }

        return $this->url($key) . '?' . implode('&', $query);
    }

    /**
     * 按官方文档计算签名，返回 Authorization 的值（q-sign-algorithm=sha1&q-ak=...&q-signature=...）。
     *
     * @param string $path 对象路径，不做 URL 编码（如 /a/b.jpg）
     * @param array<string, string> $headers 参与签名的头部
     * @param array<string, string> $params 参与签名的查询参数
     */
    public function authorization(string $method, string $path, array $headers, array $params, int $start, int $ttlSeconds): string
    {
        $keyTime = $start . ';' . ($start + $ttlSeconds);
        $signKey = hash_hmac('sha1', $keyTime, $this->secretKey);
        [$paramList, $httpParams] = self::canonical($params);
        [$headerList, $httpHeaders] = self::canonical($headers);
        $httpString = strtolower($method) . "\n" . $path . "\n" . $httpParams . "\n" . $httpHeaders . "\n";
        $stringToSign = "sha1\n" . $keyTime . "\n" . sha1($httpString) . "\n";
        $signature = hash_hmac('sha1', $stringToSign, $signKey);

        return implode('&', [
            'q-sign-algorithm=sha1',
            'q-ak=' . $this->secretId,
            'q-sign-time=' . $keyTime,
            'q-key-time=' . $keyTime,
            'q-header-list=' . $headerList,
            'q-url-param-list=' . $paramList,
            'q-signature=' . $signature,
        ]);
    }

    /**
     * 键小写后 URL 编码、值 URL 编码，按键排序，返回 [键列表（; 分隔）, k=v&k=v]。
     *
     * @param array<string, string> $pairs
     * @return array{0: string, 1: string}
     */
    private static function canonical(array $pairs): array
    {
        $encoded = [];
        foreach ($pairs as $k => $v) {
            $encoded[rawurlencode(strtolower((string) $k))] = rawurlencode((string) $v);
        }
        ksort($encoded, SORT_STRING);
        $kv = [];
        foreach ($encoded as $k => $v) {
            $kv[] = $k . '=' . $v;
        }

        return [implode(';', array_keys($encoded)), implode('&', $kv)];
    }

    /**
     * 签名用的路径不编码，请求地址里的路径逐段编码。
     */
    private function signPath(string $key): string
    {
        return '/' . ltrim($key, '/');
    }

    private function url(string $key): string
    {
        return 'https://' . $this->host() . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
    }

    private function errorMessage(string $body): string
    {
        // COS 出错时返回 XML：<Error><Code>...</Code><Message>...</Message></Error>
        if (preg_match('#<Code>(.*?)</Code>.*?<Message>(.*?)</Message>#s', $body, $m) === 1) {
            return "{$m[1]} {$m[2]}";
        }

        return mb_substr($body, 0, 200);
    }
}
