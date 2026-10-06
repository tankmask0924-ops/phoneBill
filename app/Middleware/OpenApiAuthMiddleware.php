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

namespace App\Middleware;

use App\Exception\OpenApiException;
use App\Network\ClientIpResolver;
use App\Network\IpAddress;
use App\Security\Encrypter;
use App\Security\OpenApiSigner;
use App\Service\Merchant\MerchantLookupService;
use App\Support\RedisLock;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 开放接口鉴权（商户系统调用）：app_key 找商户 → 商户启用 → IP 白名单 → timestamp 在有效期内
 * → 签名正确 → nonce 没用过（防重放）。通过后把 Merchant（来自缓存，没有余额）放进 request attribute `merchant`，
 * 参与验签的参数放进 `open_api_params`（控制器只用这份，保证用的参数就是验过签的）。
 *
 * 签名规则见 OpenApiSigner；参数来自 query 和 body（JSON 或表单）合并，值只能是标量。
 */
class OpenApiAuthMiddleware implements MiddlewareInterface
{
    #[Inject]
    protected MerchantLookupService $merchantLookup;

    #[Inject]
    protected OpenApiSigner $signer;

    #[Inject]
    protected Encrypter $encrypter;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Inject]
    protected RedisLock $lock;

    #[Inject]
    protected ConfigInterface $config;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (! is_array($body) || $body === []) {
            // 没带 Content-Type: application/json 的 JSON 请求，自己解析一下
            $decoded = json_decode((string) $request->getBody(), true);
            $body = is_array($decoded) ? $decoded : [];
        }
        $params = $request->getQueryParams() + $body;
        foreach ($params as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new OpenApiException(OpenApiException::INVALID_PARAMS, "参数 {$key} 必须是字符串或数字");
            }
        }
        foreach (['app_key', 'timestamp', 'nonce', 'sign'] as $required) {
            if (! isset($params[$required]) || $params[$required] === '') {
                throw new OpenApiException(OpenApiException::INVALID_PARAMS, "缺少参数 {$required}");
            }
        }

        $merchant = $this->merchantLookup->findByAppKey((string) $params['app_key']);
        if ($merchant === null) {
            throw new OpenApiException(OpenApiException::INVALID_APP_KEY, 'app_key 不存在');
        }
        if ($merchant->status !== 'active') {
            throw new OpenApiException(OpenApiException::MERCHANT_DISABLED, '商户已停用');
        }
        $whitelist = $merchant->ipWhitelist();
        if ($whitelist !== []) {
            $ip = $this->clientIpResolver->resolve($request);
            $allowed = $ip !== null && array_filter($whitelist, static fn (string $range) => IpAddress::inRange($ip, $range)) !== [];
            if (! $allowed) {
                throw new OpenApiException(OpenApiException::IP_NOT_ALLOWED, 'IP ' . ($ip ?? '未知') . ' 不在白名单里');
            }
        }

        $ttl = (int) $this->config->get('order.signature_ttl_seconds', 300);
        if (! ctype_digit((string) $params['timestamp']) || abs(time() - (int) $params['timestamp']) > $ttl) {
            throw new OpenApiException(OpenApiException::INVALID_TIMESTAMP, "timestamp 必须是秒级时间戳，且和服务器时间相差不超过 {$ttl} 秒");
        }
        if (! $this->signer->verify($params, $this->encrypter->decrypt($merchant->app_secret), (string) $params['sign'])) {
            throw new OpenApiException(OpenApiException::INVALID_SIGN, '签名错误');
        }
        $nonce = (string) $params['nonce'];
        if (strlen($nonce) > 64 || ! $this->lock->once("open_api:nonce:{$merchant->id}:{$nonce}", $ttl * 2)) {
            throw new OpenApiException(OpenApiException::DUPLICATE_NONCE, 'nonce 已经用过，每次请求要换一个');
        }

        return $handler->handle($request->withAttribute('merchant', $merchant)->withAttribute('open_api_params', $params));
    }
}
