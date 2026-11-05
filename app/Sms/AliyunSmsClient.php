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

namespace App\Sms;

use App\Network\HttpClient;
use RuntimeException;
use Throwable;

/**
 * 阿里云短信 SendSms 的最小客户端，不依赖 SDK，按 RPC 风格（V1 签名）自己签名：.
 *
 * - 公共参数 + 接口参数按键排序，键和值都做 percentEncode（rawurlencode，空格是 %20，~ 不编码），用 & 连起来；
 * - StringToSign = GET&%2F&percentEncode(上一步的串)；
 * - Signature = Base64(HMAC-SHA1(AccessKeySecret + "&", StringToSign))，作为 Signature 参数一起 GET 过去；
 * - 返回 JSON，Code 为 OK 才算发送成功。
 */
class AliyunSmsClient
{
    public const ENDPOINT = 'https://dysmsapi.aliyuncs.com/';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $accessKeyId,
        private readonly string $accessKeySecret,
    ) {
    }

    /**
     * 发一条模板短信，失败抛异常（消息里带阿里云的错误码和说明）。
     *
     * @param array<string, string> $params 模板变量
     */
    public function send(string $mobile, string $signName, string $templateCode, array $params): void
    {
        $query = $this->signedQuery([
            'Action' => 'SendSms',
            'Version' => '2017-05-25',
            'RegionId' => 'cn-hangzhou',
            'PhoneNumbers' => $mobile,
            'SignName' => $signName,
            'TemplateCode' => $templateCode,
            'TemplateParam' => (string) json_encode($params, JSON_UNESCAPED_UNICODE),
        ]);
        try {
            $response = $this->http->get(self::ENDPOINT, $query, 8.0);
        } catch (Throwable $e) {
            throw new RuntimeException('请求阿里云短信接口失败：' . $e->getMessage(), 0, $e);
        }
        $body = json_decode($response['body'], true);
        if (! is_array($body)) {
            throw new RuntimeException('阿里云短信接口返回异常：HTTP ' . $response['status']);
        }
        if (($body['Code'] ?? null) !== 'OK') {
            throw new RuntimeException('阿里云短信发送失败：' . ($body['Code'] ?? '') . ' ' . ($body['Message'] ?? ''));
        }
    }

    /**
     * 加上公共参数和签名。$nonce / $timestamp 只给测试固定用。
     *
     * @param array<string, string> $params
     * @return array<string, string>
     */
    public function signedQuery(array $params, ?string $nonce = null, ?string $timestamp = null): array
    {
        $params += [
            'AccessKeyId' => $this->accessKeyId,
            'Format' => 'JSON',
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureVersion' => '1.0',
            'SignatureNonce' => $nonce ?? bin2hex(random_bytes(16)),
            'Timestamp' => $timestamp ?? gmdate('Y-m-d\TH:i:s\Z'),
        ];
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = self::encode((string) $key) . '=' . self::encode($value);
        }
        $stringToSign = 'GET&%2F&' . self::encode(implode('&', $pairs));
        $params['Signature'] = base64_encode(hash_hmac('sha1', $stringToSign, $this->accessKeySecret . '&', true));

        return $params;
    }

    private static function encode(string $value): string
    {
        // rawurlencode 已经是 RFC 3986：空格 %20、* 编码、~ 不编码
        return rawurlencode($value);
    }
}
