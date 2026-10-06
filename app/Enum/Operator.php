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
 * 运营商。号段库、供应商商品、平台商品价格里都存这里的编码。
 */
enum Operator: string
{
    case Cmcc = 'cmcc';
    case Cucc = 'cucc';
    case Ctcc = 'ctcc';
    case Cbn = 'cbn';

    public function label(): string
    {
        return match ($this) {
            self::Cmcc => '移动',
            self::Cucc => '联通',
            self::Ctcc => '电信',
            self::Cbn => '广电',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $op) => $op->value, self::cases());
    }
}
