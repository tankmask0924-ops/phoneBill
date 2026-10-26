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
namespace App\Service\Merchant;

use App\Exception\OpenApiException;
use App\Model\Merchant;
use App\Service\AbstractService;
use App\Support\RateLimiter;
use Hyperf\Di\Annotation\Inject;

/**
 * 商户下单接口限流：每 rate_limit_seconds 秒最多 rate_limit_orders 单（任一为 0 不限），在商户列表「编辑」里设。
 * 只限开放接口下单，查单、查余额和商户后台的批量下单不限。
 */
class MerchantRateLimitService extends AbstractService
{
    #[Inject]
    protected RateLimiter $limiter;

    public function check(Merchant $merchant): void
    {
        $seconds = (int) ($merchant->rate_limit_seconds ?? 0);
        $limit = (int) ($merchant->rate_limit_orders ?? 0);
        if ($seconds <= 0 || $limit <= 0) {
            return;
        }
        if (! $this->limiter->attempt("open_api:recharge:{$merchant->id}", $limit, $seconds)) {
            throw new OpenApiException(OpenApiException::RATE_LIMITED, "下单太频繁：每 {$seconds} 秒最多 {$limit} 单，请稍后再试");
        }
    }
}
