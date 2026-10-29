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

namespace HyperfTest\Cases\Mobile;

use App\Enum\City;
use PHPUnit\Framework\TestCase;

/**
 * 号段库城市名统一写法（号段库的实际写法）。
 *
 * @internal
 * @coversNothing
 */
class CityTest extends TestCase
{
    public function testNormalize()
    {
        $cases = [
            '广州市' => '广州',
            '广州' => '广州',
            '北京市' => '北京',
            '和田地区' => '和田',
            '锡林郭勒盟' => '锡林郭勒',
            '凉山彝族自治州' => '凉山',
            '黔东南苗族侗族自治州' => '黔东南',
            '巴音郭楞蒙古自治州' => '巴音郭楞',
            '巴州' => '巴音郭楞',
            '克州' => '克孜勒苏',
            '伊犁哈萨克自治州（奎屯）市' => '奎屯',
            '阜阳\/亳州' => '阜阳/亳州',
            '莱芜区' => '莱芜',
            '版纳' => '西双版纳',
            '神农架林区' => '神农架',
            '雄安新区' => '雄安新区',
            ' 深圳市 ' => '深圳',
        ];
        foreach ($cases as $raw => $expected) {
            $this->assertSame($expected, City::normalize($raw), $raw);
        }
        $this->assertNull(City::normalize(null));
        $this->assertNull(City::normalize(''));
    }
}
