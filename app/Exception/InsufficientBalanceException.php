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
 * 商户余额不够扣。
 */
class InsufficientBalanceException extends RuntimeException
{
    public function __construct(public readonly string $balance, public readonly string $required)
    {
        parent::__construct("余额不足：当前 {$balance} 元，需要 {$required} 元");
    }
}
