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
 * 商户可用额度（余额 + 授信额度）不够扣。
 */
class InsufficientBalanceException extends RuntimeException
{
    public function __construct(public readonly string $balance, public readonly string $required, public readonly string $creditLimit = '0.00')
    {
        parent::__construct(bccomp($creditLimit, '0', 2) > 0
            ? "可用额度不足：余额 {$balance} 元，授信 {$creditLimit} 元，需要 {$required} 元"
            : "余额不足：当前 {$balance} 元，需要 {$required} 元");
    }
}
