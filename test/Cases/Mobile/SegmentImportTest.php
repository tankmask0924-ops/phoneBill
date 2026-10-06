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

use App\Enum\Province;
use App\Model\MobileSegment;
use App\Service\Mobile\MobileSegmentService;
use App\Service\Mobile\SegmentImportService;
use PHPUnit\Framework\TestCase;

use function Hyperf\Support\make;

/**
 * 号段库导入：运营商、省份、虚拟运营商号段的转换，以及覆盖写入、导入后清缓存。
 *
 * @internal
 * @coversNothing
 */
class SegmentImportTest extends TestCase
{
    /** 测试用的号段（100 开头，真实号段库里没有） */
    private array $segments = [];

    protected function tearDown(): void
    {
        MobileSegment::whereIn('segment', $this->segments)->get()->each(fn (MobileSegment $s) => $s->delete());
        parent::tearDown();
    }

    public function testProvinceFullNames()
    {
        $this->assertSame('广东', Province::fromFullName('广东省'));
        $this->assertSame('北京', Province::fromFullName('北京市'));
        $this->assertSame('广西', Province::fromFullName('广西壮族自治区'));
        $this->assertSame('宁夏', Province::fromFullName('宁夏回族自治区'));
        $this->assertSame('新疆', Province::fromFullName('新疆维吾尔自治区'));
        $this->assertSame('内蒙古', Province::fromFullName('内蒙古自治区'));
        $this->assertSame('西藏', Province::fromFullName('西藏自治区'));
        $this->assertNull(Province::fromFullName('火星省'));
    }

    public function testVirtualSegmentsIgnoreTheLabelInTheData()
    {
        // 虚商号段：不管标的是什么，都是虚拟运营商，运营商按号段分配规则
        foreach ([
            ['1620001', '中国移动', 'ctcc'],
            ['1650001', '中国分享', 'cmcc'],
            ['1670001', '中国电信', 'cucc'],
            ['1710001', '中国移动', 'cucc'],
            ['1700001', '中国阿里', 'ctcc'],
            ['1705001', '中国联通', 'cmcc'],
            ['1709001', '中国京东', 'cucc'],
        ] as [$segment, $label, $operator]) {
            [$row] = SegmentImportService::convert($segment, $label, '广东省', '深圳市');
            $this->assertTrue($row['is_virtual'], $segment);
            $this->assertSame($operator, $row['operator'], $segment);
        }

        [$row] = SegmentImportService::convert('1300001', '联通', '江苏省', '常州市');
        $this->assertSame(['segment' => '1300001', 'operator' => 'cucc', 'province' => '江苏', 'city' => '常州市', 'is_virtual' => false], $row);
        $this->assertSame('cbn', SegmentImportService::convert('1920001', '中国广电', '北京市', '北京市')[0]['operator']);
        $this->assertSame('运营商认不出：中国分享', SegmentImportService::convert('1300001', '中国分享', '北京市', null)[1], '虚商品牌出现在普通号段：跳过');
        $this->assertNull(SegmentImportService::convert('1300001', '中国移动', '火星', null)[0]);
    }

    public function testImportUpsertsAndClearsCache()
    {
        $this->segments[] = $a = $this->freeSegment();
        $this->segments[] = $b = $this->freeSegment();
        $file = tempnam(sys_get_temp_dir(), 'seg');
        $lines = fn (string $provinceA) => implode("\n", [
            'SET NAMES utf8mb4;',
            "INSERT INTO `recharge_phone_prefix_info` VALUES (1, '{$a}', '0', '{$provinceA}', '广州市', '中国移动', '2026-03-27 11:55:09', '2026-05-06 17:38:11', 1, 1, 0, 1);",
            "INSERT INTO `recharge_phone_prefix_info` VALUES (2, '{$b}', NULL, '四川省', NULL, '电信', '2026-03-27 11:55:09', '2026-05-06 17:38:11', 1, 1, 0, 0);",
            'INSERT INTO `something_else` VALUES (1);',
        ]) . "\n";

        $service = make(SegmentImportService::class);
        file_put_contents($file, $lines('广东省'));
        $stats = $service->import($file);
        $this->assertSame(2, $stats['imported']);
        $this->assertSame(1, $stats['unverified']);
        $this->assertSame(1, $stats['unparsed']);
        $this->assertSame('广东', make(MobileSegmentService::class)->identify($a . '0000')->province, '进了缓存');

        // 再导一次：覆盖更新，并清掉缓存
        file_put_contents($file, $lines('湖南省'));
        $service->import($file);
        unlink($file);
        $this->assertSame('湖南', make(MobileSegmentService::class)->identify($a . '0000')->province);
        $this->assertSame(1, MobileSegment::where('segment', $a)->count());
        $this->assertSame('ctcc', MobileSegment::where('segment', $b)->value('operator'));
        $this->assertNull(MobileSegment::where('segment', $b)->value('city'));
    }

    private function freeSegment(): string
    {
        do {
            $segment = '100' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (MobileSegment::where('segment', $segment)->exists() || in_array($segment, $this->segments, true));

        return $segment;
    }
}
