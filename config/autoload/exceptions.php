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
use App\Exception\Handler\AppExceptionHandler;
use App\Exception\Handler\OpenApiExceptionHandler;
use Hyperf\HttpServer\Exception\Handler\HttpExceptionHandler;
use Hyperf\Validation\ValidationExceptionHandler;

return [
    'handler' => [
        'http' => [
            OpenApiExceptionHandler::class,
            ValidationExceptionHandler::class,
            HttpExceptionHandler::class,
            AppExceptionHandler::class,
        ],
    ],
];
