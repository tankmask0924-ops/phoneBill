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
 * 城市名统一写法。号段库的城市写法不统一（广州 / 广州市、巴州 / 巴音郭楞蒙古自治州），记到订单上、按市统计前先转成简称。
 * 号段库本身不改，保留原始数据。
 */
final class City
{
    /** 少数民族自治州名里的民族，去掉「自治州」后再去掉它们：凉山彝族 → 凉山、黔东南苗族侗族 → 黔东南 */
    private const ETHNIC = '(蒙古族|蒙古|回族|藏族|羌族|彝族|苗族|侗族|布依族|土家族|白族|傣族|景颇族|傈僳族|哈尼族|壮族|朝鲜族|哈萨克|柯尔克孜|维吾尔)';

    /** 号段库里的简称 → 统一的写法 */
    private const ALIASES = [
        '巴州' => '巴音郭楞',
        '克州' => '克孜勒苏',
        '博州' => '博尔塔拉',
        '版纳' => '西双版纳',
        '莱芜区' => '莱芜',
    ];

    /**
     * 广州市 → 广州、和田地区 → 和田、锡林郭勒盟 → 锡林郭勒、凉山彝族自治州 → 凉山、
     * 伊犁哈萨克自治州（奎屯）市 → 奎屯（括号里的是实际城市）。几个市共用的号段保留原样：阜阳\/亳州 → 阜阳/亳州。空的返回 null。
     */
    public static function normalize(?string $city): ?string
    {
        $name = str_replace('\/', '/', trim((string) $city));
        if (preg_match('/[（(]([^）)]+)[）)]/u', $name, $m) === 1) {
            $name = $m[1];
        }
        if (preg_match('/自治州$/u', $name) === 1) {
            $name = (string) preg_replace('/' . self::ETHNIC . '+自治州$/u', '', $name);
        }
        $name = (string) preg_replace('/(市|地区|盟|林区|自治州)$/u', '', $name);
        $name = self::ALIASES[$name] ?? $name;

        return $name === '' ? null : mb_substr($name, 0, 32);
    }
}
