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
use function Hyperf\Support\env;

return [
    // 数据库里敏感字段（供应商接口参数等）的加密密钥，十六进制编码的 32 字节随机数
    'key' => env('APP_ENCRYPTION_KEY', ''),
];
