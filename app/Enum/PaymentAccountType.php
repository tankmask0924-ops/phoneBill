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

namespace App\Enum;

/**
 * 收款账户、商户付款账号的类型：只支持支付宝和对公银行账户。
 */
enum PaymentAccountType: string
{
    case Alipay = 'alipay';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Alipay => '支付宝',
            self::Bank => '对公账户',
        };
    }
}
