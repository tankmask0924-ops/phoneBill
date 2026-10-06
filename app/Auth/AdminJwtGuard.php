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

use DomainException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

use function Hyperf\Support\env;

/**
 * 管理后台（web/）登录态：HS256 JWT，签名密钥读 `.env` 的 `ADMIN_JWT_SECRET`。
 *
 * 有效期 8 小时（一个工作日），到期重新登录，不做 refresh token。
 * token 里带密码版本 pv（见 passwordVersion()），改密码后之前签发的 token 全部失效。
 */
class AdminJwtGuard
{
    private const TTL_SECONDS = 8 * 3600;

    private const ALGO = 'HS256';

    /**
     * 签发一个新 JWT，claims：sub=admin_user_id（字符串，JWT 惯例）、iat=签发时间、exp=签发时间+TTL、
     * pv=密码版本（见 passwordVersion()），改密码后旧 token 的 pv 对不上，中间件按失效处理。
     */
    public function issue(int $adminUserId, string $passwordHash): string
    {
        $now = time();

        return JWT::encode([
            'sub' => (string) $adminUserId,
            'pv' => $this->passwordVersion($passwordHash),
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
        ], $this->secret(), self::ALGO);
    }

    /**
     * 解码并校验 JWT（签名、过期时间、算法），返回 sub 里的 admin_user_id 和 pv；
     * 任何失败（签名不对、已过期、格式不对、算法不对、sub 缺失或非数字、pv 缺失）一律返回 null，
     * 不让 firebase/php-jwt 的异常逃逸到中间件里。没有 pv 的旧 token 也按无效处理。
     *
     * @return null|array{id: int, pv: string}
     */
    public function resolve(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        try {
            $payload = JWT::decode($token, new Key($this->secret(), self::ALGO));
        } catch (DomainException|ExpiredException|SignatureInvalidException|UnexpectedValueException) {
            // UnexpectedValueException（ExpiredException/SignatureInvalidException 是它的子类）
            // 覆盖签名不对、已过期、segment 数量不对、算法不支持等；DomainException
            // 单独捕获——它不是 UnexpectedValueException 的子类，是 firebase/php-jwt
            // 在 base64 解码出的内容不是合法 JSON（典型如乱码 token）时抛出的。
            return null;
        }

        $id = $this->extractAdminUserId($payload);
        $pv = $payload->pv ?? null;
        if ($id === null || ! is_string($pv)) {
            return null;
        }

        return ['id' => $id, 'pv' => $pv];
    }

    /**
     * 密码哈希的指纹，放进 token 里；用 HMAC 而不是直接截取哈希，JWT 载荷是明文可读的，
     * 不能把密码哈希的任何片段暴露出去。
     */
    public function passwordVersion(string $passwordHash): string
    {
        return substr(hash_hmac('sha256', $passwordHash, $this->secret()), 0, 16);
    }

    private function extractAdminUserId(stdClass $payload): ?int
    {
        $sub = $payload->sub ?? null;
        if (! is_string($sub) && ! is_int($sub)) {
            return null;
        }

        $sub = (string) $sub;
        if (! ctype_digit($sub)) {
            return null;
        }

        return (int) $sub;
    }

    private function secret(): string
    {
        $secret = env('ADMIN_JWT_SECRET');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('ADMIN_JWT_SECRET is not configured.');
        }

        return $secret;
    }
}
