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

namespace App\Exception;

use RuntimeException;

/**
 * 开放接口的业务错误，由 OpenApiExceptionHandler 转成 {code, message, data: null}，HTTP 状态码统一 200。
 * 错误码见 docs/api.md。
 */
class OpenApiException extends RuntimeException
{
    public const INVALID_PARAMS = 'INVALID_PARAMS';

    public const INVALID_APP_KEY = 'INVALID_APP_KEY';

    public const MERCHANT_DISABLED = 'MERCHANT_DISABLED';

    public const IP_NOT_ALLOWED = 'IP_NOT_ALLOWED';

    public const INVALID_TIMESTAMP = 'INVALID_TIMESTAMP';

    public const INVALID_SIGN = 'INVALID_SIGN';

    public const DUPLICATE_NONCE = 'DUPLICATE_NONCE';

    public const PRODUCT_NOT_AVAILABLE = 'PRODUCT_NOT_AVAILABLE';

    public const UNSUPPORTED_MOBILE = 'UNSUPPORTED_MOBILE';

    public const PRICE_NOT_SET = 'PRICE_NOT_SET';

    public const NO_CHANNEL = 'NO_CHANNEL';

    public const BLACKLISTED = 'BLACKLISTED';

    public const DUPLICATE_RECHARGE = 'DUPLICATE_RECHARGE';

    public const INSUFFICIENT_BALANCE = 'INSUFFICIENT_BALANCE';

    public const ORDER_NOT_FOUND = 'ORDER_NOT_FOUND';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
