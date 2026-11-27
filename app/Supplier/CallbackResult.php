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

/**
 * 驱动解析供应商回调的结果。
 */
final class CallbackResult
{
    public function __construct(
        /** 验签是否通过；不通过时 result 不会被采用 */
        public readonly bool $verified,
        /** 原样回给供应商的响应体（各家要求不同，比如 success / ok / JSON） */
        public readonly string $reply,
        /** 我们传给供应商的订单号，和 supplierOrderNo 至少有一个 */
        public readonly ?string $attemptNo = null,
        public readonly ?string $supplierOrderNo = null,
        public readonly ?RechargeResult $result = null,
        /**
         * 认出了是哪笔提交，但回调后的确认查单没拿到明确结果（接口出错、超时、查无此单等）：按处理中算，
         * 带着原因和查单报文。SupplierCallbackService 记到这次提交的查单记录上，过一会自动重新确认。
         */
        public readonly ?RechargeResult $unconfirmed = null,
    ) {
    }
}
