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

namespace App\Exception\Handler;

use App\Exception\OpenApiException;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Logger\LoggerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * 开放接口的业务错误统一返回 {code, message, data: null}，HTTP 200，方便商户按 code 判断。
 * 同时记到 open 日志渠道（不记签名），商户问「为什么被拒」时能查到。
 */
class OpenApiExceptionHandler extends ExceptionHandler
{
    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        /* @var OpenApiException $throwable */
        $this->stopPropagation();
        $this->log($throwable);
        $body = json_encode(['code' => $throwable->errorCode, 'message' => $throwable->getMessage(), 'data' => null], JSON_UNESCAPED_UNICODE);

        return $response->withStatus(200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new SwooleStream((string) $body));
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof OpenApiException;
    }

    private function log(OpenApiException $e): void
    {
        $request = Context::get(ServerRequestInterface::class);
        $params = [];
        if ($request instanceof ServerRequestInterface) {
            $body = $request->getParsedBody();
            $params = array_merge($request->getQueryParams(), is_array($body) ? $body : []);
        }
        $pick = static fn (string $key) => is_scalar($params[$key] ?? null) ? (string) $params[$key] : null;
        $this->loggerFactory->get('open')->warning('开放接口拒绝请求', [
            'path' => $request instanceof ServerRequestInterface ? $request->getUri()->getPath() : null,
            'code' => $e->errorCode,
            'message' => $e->getMessage(),
            'app_key' => $pick('app_key'),
            'merchant_order_no' => $pick('merchant_order_no'),
            'mobile' => $pick('mobile'),
            'product_code' => $pick('product_code'),
        ]);
    }
}
