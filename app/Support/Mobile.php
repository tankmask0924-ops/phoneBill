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

namespace App\Support;

/**
 * 大陆手机号（收短信用）。
 */
final class Mobile
{
    /**
     * 合法返回去掉空白的 11 位号码，否则 null。
     */
    public static function normalize(mixed $value): ?string
    {
        $mobile = is_string($value) || is_int($value) ? trim((string) $value) : '';

        return preg_match('/^1[3-9]\d{9}$/', $mobile) === 1 ? $mobile : null;
    }

    /**
     * 138****1234。
     */
    public static function mask(string $mobile): string
    {
        return strlen($mobile) === 11 ? substr($mobile, 0, 3) . '****' . substr($mobile, -4) : $mobile;
    }
}
