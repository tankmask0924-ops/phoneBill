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
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 开放接口的业务错误统一返回 {code, message, data: null}，HTTP 200，方便商户按 code 判断。
 */
class OpenApiExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        /* @var OpenApiException $throwable */
        $this->stopPropagation();
        $body = json_encode(['code' => $throwable->errorCode, 'message' => $throwable->getMessage(), 'data' => null], JSON_UNESCAPED_UNICODE);

        return $response->withStatus(200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new SwooleStream((string) $body));
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof OpenApiException;
    }
}
