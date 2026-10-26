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

namespace App\Payment;

use App\Network\HttpClient;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * 支付宝开放平台的最小客户端（公钥模式，RSA2），不依赖 SDK，按开放平台的通用协议自己签名、验签：.
 *
 * - 请求：公共参数 + biz_content（JSON），去掉 sign 和空值按参数名 ASCII 升序拼 k=v&...，用应用私钥 SHA256withRSA 签名，
 *   base64 后放在 sign 里，表单 POST 到网关；
 * - 响应：{"<method 点换下划线>_response": {...}, "sign": "..."}，用支付宝公钥对 _response 节点的原始 JSON 字符串验签；
 *   code 是 10000 才算成功。
 *
 * 目前只用账务明细查询（alipay.data.bill.accountlog.query）按支付宝订单号认转账到账。
 */
class AlipayClient
{
    public const GATEWAY = 'https://openapi.alipay.com/gateway.do';

    public const METHOD_ACCOUNT_LOG = 'alipay.data.bill.accountlog.query';

    /** 账务明细的分页大小只能是 1000～2000 */
    public const PAGE_SIZE = 2000;

    /** 一次最多翻这么多页，防止异常数据下死循环 */
    private const MAX_PAGES = 20;

    private const CODE_SUCCESS = '10000';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $appId,
        /** 应用私钥（PKCS8 或 PKCS1，带不带 PEM 头尾、换行都行） */
        private readonly string $privateKey,
        /** 支付宝公钥（不是应用公钥） */
        private readonly string $alipayPublicKey,
    ) {
    }

    /**
     * 查一段时间内的账务明细（左闭右开，间隔不能超过 31 天），自动翻页，返回全部 detail_list 条目。
     *
     * @param null|string $alipayOrderNo 只查这笔支付宝订单的流水
     * @return list<array<string, mixed>>
     */
    public function accountLogs(string $startTime, string $endTime, ?string $alipayOrderNo = null): array
    {
        $items = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $biz = [
                'start_time' => $startTime,
                'end_time' => $endTime,
                'page_no' => (string) $page,
                'page_size' => (string) self::PAGE_SIZE,
            ];
            if ($alipayOrderNo !== null) {
                $biz['alipay_order_no'] = $alipayOrderNo;
            }
            $result = $this->call(self::METHOD_ACCOUNT_LOG, $biz);
            $list = is_array($result['detail_list'] ?? null) ? array_values(array_filter($result['detail_list'], 'is_array')) : [];
            $items = array_merge($items, $list);
            if (count($list) < self::PAGE_SIZE || count($items) >= (int) ($result['total_size'] ?? 0)) {
                break;
            }
        }

        return $items;
    }

    /**
     * 调一个接口，返回 _response 节点。网络出错、HTTP 非 200、验签不过、code 不是 10000 都抛异常。
     *
     * @param array<string, string> $bizContent
     * @return array<string, mixed>
     */
    public function call(string $method, array $bizContent): array
    {
        $params = [
            'app_id' => $this->appId,
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => (string) json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $params['sign'] = self::sign($params, $this->privateKey);
        $response = $this->http->postForm(self::GATEWAY . '?charset=utf-8', $params, 10.0);
        if ($response['status'] !== 200) {
            throw new RuntimeException('调用支付宝失败：HTTP ' . $response['status']);
        }
        $body = $response['body'];
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('支付宝返回无法解析：' . mb_substr($body, 0, 200));
        }
        $node = str_replace('.', '_', $method) . '_response';
        if (! is_array($decoded[$node] ?? null)) {
            $error = is_array($decoded['error_response'] ?? null) ? $decoded['error_response'] : [];
            throw new RuntimeException('支付宝返回错误：' . self::describeError($error ?: ['msg' => mb_substr($body, 0, 200)]));
        }
        $data = $decoded[$node];
        $sign = is_string($decoded['sign'] ?? null) ? $decoded['sign'] : '';
        // 应用配置不对时支付宝可能不带签名直接返回错误，这时先把错误报出来
        if ($sign === '' && (string) ($data['code'] ?? '') !== self::CODE_SUCCESS) {
            throw new RuntimeException('支付宝返回错误：' . self::describeError($data));
        }
        $raw = self::rawNode($body, $node);
        if ($sign === '' || $raw === null || ! self::verify($raw, $sign, $this->alipayPublicKey)) {
            throw new RuntimeException('支付宝响应验签失败，请检查支付宝公钥是否填对（不是应用公钥）');
        }
        if ((string) ($data['code'] ?? '') !== self::CODE_SUCCESS) {
            throw new RuntimeException('支付宝返回错误：' . self::describeError($data));
        }

        return $data;
    }

    /**
     * 请求签名：去掉 sign 和空值，按参数名升序拼 k=v&...，SHA256withRSA，base64。
     *
     * @param array<string, string> $params
     */
    public static function sign(array $params, string $privateKey): string
    {
        $key = self::privateKey($privateKey);
        if ($key === null) {
            throw new RuntimeException('应用私钥格式不对');
        }
        openssl_sign(self::signContent($params), $signature, $key, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * @param array<string, string> $params
     */
    public static function signContent(array $params): string
    {
        unset($params['sign']);
        $params = array_filter($params, static fn ($v) => $v !== '' && $v !== null);
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return implode('&', $pairs);
    }

    public static function verify(string $content, string $sign, string $publicKey): bool
    {
        $key = self::publicKey($publicKey);
        $signature = base64_decode($sign, true);

        return $key !== null && $signature !== false && openssl_verify($content, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 能不能当应用私钥用（保存配置时校验）。
     */
    public static function isPrivateKey(string $value): bool
    {
        return self::privateKey($value) !== null;
    }

    public static function isPublicKey(string $value): bool
    {
        return self::publicKey($value) !== null;
    }

    /**
     * 取响应里 _response 节点的原始 JSON 字符串（验签要用原文，不能重新编码）。
     */
    public static function rawNode(string $body, string $node): ?string
    {
        $marker = '"' . $node . '":';
        $start = strpos($body, $marker);
        $signAt = strrpos($body, ',"sign"');
        if ($start === false || $signAt === false || $signAt <= $start) {
            return null;
        }
        $start += strlen($marker);

        return trim(substr($body, $start, $signAt - $start));
    }

    private static function privateKey(string $value): ?OpenSSLAsymmetricKey
    {
        $body = self::keyBody($value);
        if ($body === '') {
            return null;
        }
        foreach (['PRIVATE KEY', 'RSA PRIVATE KEY'] as $type) {
            $key = openssl_pkey_get_private("-----BEGIN {$type}-----\n" . chunk_split($body, 64, "\n") . "-----END {$type}-----\n");
            if ($key !== false) {
                return $key;
            }
        }

        return null;
    }

    private static function publicKey(string $value): ?OpenSSLAsymmetricKey
    {
        $body = self::keyBody($value);
        if ($body === '') {
            return null;
        }
        $key = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split($body, 64, "\n") . "-----END PUBLIC KEY-----\n");

        return $key === false ? null : $key;
    }

    /**
     * 去掉 PEM 头尾和所有空白，只留 base64 正文。
     */
    private static function keyBody(string $value): string
    {
        $value = (string) preg_replace('/-----(BEGIN|END)[A-Z ]+-----/', '', $value);

        return (string) preg_replace('/\s+/', '', $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function describeError(array $data): string
    {
        $parts = [];
        foreach (['code', 'msg', 'sub_code', 'sub_msg'] as $field) {
            if (is_scalar($data[$field] ?? null) && (string) $data[$field] !== '') {
                $parts[] = (string) $data[$field];
            }
        }

        return $parts === [] ? '未知错误' : implode(' ', $parts);
    }
}
