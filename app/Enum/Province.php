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
 * 省份简称。供应商的覆盖省份、号段库的省份都用这里的名字，号段库导入时要转成同样的写法。
 */
final class Province
{
    /** 供应商覆盖全国 */
    public const ALL = '*';

    public const NAMES = [
        '北京', '天津', '河北', '山西', '内蒙古', '辽宁', '吉林', '黑龙江',
        '上海', '江苏', '浙江', '安徽', '福建', '江西', '山东',
        '河南', '湖北', '湖南', '广东', '广西', '海南',
        '重庆', '四川', '贵州', '云南', '西藏',
        '陕西', '甘肃', '青海', '宁夏', '新疆',
    ];

    public static function isValid(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }
}
