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

namespace App\Middleware;

use App\Auth\MerchantJwtGuard;
use App\Dao\MerchantDao;
use App\Dao\MerchantUserDao;
use App\Service\Sms\SmsService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 商户后台登录态鉴权：校验 `Authorization: Bearer <JWT>`（MerchantJwtGuard 签发的），
 * 通过后把 MerchantUser 放进 request attribute `merchant_user`、所属 Merchant 放进 `merchant`。
 *
 * 商户接口查数据一律用这里放进去的 merchant 过滤，不信任前端传来的商户 ID。
 *
 * 平台启用了短信时，没绑定手机的账号只能调 ALLOWED_WITHOUT_MOBILE 里的接口（查自己、绑定手机、改密码），
 * 其他一律 403，必须先绑定手机；平台没启用短信时没法绑定，照常放行。
 */
class MerchantAuthMiddleware implements MiddlewareInterface
{
    /** 没绑定手机时也能调的接口 */
    public const ALLOWED_WITHOUT_MOBILE = [
        '/merchant/auth/me',
        '/merchant/auth/mobile/code',
        '/merchant/auth/mobile',
        '/merchant/auth/password',
    ];

    #[Inject]
    protected MerchantJwtGuard $tokenGuard;

    #[Inject]
    protected MerchantUserDao $merchantUserDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected SmsService $smsService;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        $claims = str_starts_with($header, 'Bearer ') ? $this->tokenGuard->resolve(trim(substr($header, 7))) : null;
        if ($claims === null) {
            throw new HttpException(401, '未登录或登录已过期');
        }

        $user = $this->merchantUserDao->find($claims['id']);
        if (! $user || $user->status !== 'active') {
            throw new HttpException(401, '未登录或登录已过期');
        }
        // 改过密码（自己改或被平台重置）之后，之前签发的 token 一律失效
        if (! hash_equals($this->tokenGuard->passwordVersion($user->password), $claims['pv'])) {
            throw new HttpException(401, '密码已修改，请重新登录');
        }
        $merchant = $this->merchantDao->find($user->merchant_id);
        if (! $merchant) {
            throw new HttpException(401, '未登录或登录已过期');
        }
        if (($user->mobile ?? '') === ''
            && ! in_array($request->getUri()->getPath(), self::ALLOWED_WITHOUT_MOBILE, true)
            && $this->smsService->available()) {
            throw new HttpException(403, '请先绑定手机号');
        }

        return $handler->handle($request->withAttribute('merchant_user', $user)->withAttribute('merchant', $merchant));
    }
}
