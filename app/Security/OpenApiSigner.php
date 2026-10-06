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

namespace App\Security;

/**
 * 开放接口签名：除 sign 外、值不为空的参数按参数名升序，拼成 k1=v1&k2=v2，
 * 用商户的 AppSecret 做 HMAC-SHA256，结果取小写十六进制。
 *
 * 商户调我们、我们通知商户，用的是同一套签名。
 */
class OpenApiSigner
{
    /**
     * @param array<string, mixed> $params 值必须是标量
     */
    public function sign(array $params, string $secret): string
    {
        return hash_hmac('sha256', $this->canonical($params), $secret);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function verify(array $params, string $secret, string $sign): bool
    {
        return hash_equals($this->sign($params, $secret), strtolower($sign));
    }

    /**
     * @param array<string, mixed> $params
     */
    public function canonical(array $params): string
    {
        unset($params['sign']);
        $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }

        return implode('&', $pairs);
    }
}
