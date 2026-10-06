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

namespace App\Auth;

/**
 * 商户后台（merchant-web/）登录态，签名密钥读 `.env` 的 `MERCHANT_JWT_SECRET`，规则见 JwtGuard。
 */
class MerchantJwtGuard extends JwtGuard
{
    protected function audience(): string
    {
        return 'merchant';
    }

    protected function secretEnv(): string
    {
        return 'MERCHANT_JWT_SECRET';
    }
}
