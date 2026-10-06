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
 * 交给驱动的一次充值 / 查单请求。
 */
final class RechargeRequest
{
    /**
     * @param array<string, string> $config 解密后的供应商接口参数
     */
    public function __construct(
        /** 传给供应商的订单号，供应商回调时用它找回这次提交 */
        public readonly string $attemptNo,
        public readonly string $mobile,
        public readonly int $faceValue,
        public readonly string $operator,
        public readonly string $province,
        /** 供应商侧商品编码 */
        public readonly string $externalCode,
        public readonly array $config,
        /** 查单时带上供应商之前返回的订单号 */
        public readonly ?string $supplierOrderNo = null,
    ) {
    }
}
