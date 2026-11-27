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

namespace App\Supplier;

use RuntimeException;
use Throwable;

/**
 * 调供应商接口没拿到明确结果：连不上、超时，或者回调后的确认查单上游回复不明确（查无此单、单号对不上、报错）。
 * 带着按处理中算的结果和报文（实际发出的请求、上游原始返回），调用方记到提交上，排查时能看到上游回了什么。
 *
 * - 驱动在 call() 里调接口抛异常时抛它（结果是 RechargeResult::callFailed，带请求报文），SupplierGateway 直接用这个结果；
 * - 回调后的确认查单没拿到明确结果时也抛它，驱动在 parseCallback() 里放进 CallbackResult::$unconfirmed。
 */
final class UnclearResultException extends RuntimeException
{
    public function __construct(public readonly RechargeResult $result, ?Throwable $previous = null)
    {
        parent::__construct((string) $result->message, 0, $previous);
    }
}
