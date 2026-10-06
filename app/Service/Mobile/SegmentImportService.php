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

namespace App\Service\Mobile;

use App\Dao\MobileSegmentDao;
use App\Enum\Operator;
use App\Enum\Province;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use RuntimeException;

/**
 * 号段库导入：读 Navicat 导出的 recharge_phone_prefix_info 表（每行一条 INSERT），按号段覆盖写入 mobile_segments。
 *
 * 转换规则（docs/project.md 2.3）：
 * - 162 / 165 / 167 / 170 / 171 是虚拟运营商专用号段，不管数据里标的是什么，一律标为虚拟运营商（下单拒绝），
 *   所属运营商按号段分配规则判断；数据源对这几个号段的运营商标注不可靠；
 * - 其他号段：中国移动 / 移动 → cmcc、中国联通 / 联通 → cucc、中国电信 / 电信 → ctcc、中国广电 → cbn；
 * - 省份全称转简称；认不出的运营商、省份跳过并计数；
 * - 「未校验」（is_ok = 0）的照样导入，不导入的话这些号码会因为查不到号段被拒单。
 */
class SegmentImportService extends AbstractService
{
    private const BATCH = 2000;

    /** INSERT INTO `recharge_phone_prefix_info` VALUES (id, '号段', 区号, 省份, 城市, 运营商, 创建时间, 更新时间, creator, updater, deleted, is_ok); */
    private const LINE = "/^INSERT INTO `recharge_phone_prefix_info` VALUES \\(\\d+, '(\\d{7})', (?:NULL|'[^']*'), (NULL|'[^']*'), (NULL|'[^']*'), (NULL|'[^']*'), '[^']*', '[^']*', \\d+, \\d+, (\\d), (NULL|\\d)\\);\\s*$/u";

    private const OPERATOR_NAMES = [
        '中国移动' => 'cmcc', '移动' => 'cmcc',
        '中国联通' => 'cucc', '联通' => 'cucc',
        '中国电信' => 'ctcc', '电信' => 'ctcc',
        '中国广电' => 'cbn', '广电' => 'cbn',
    ];

    #[Inject]
    protected MobileSegmentDao $mobileSegmentDao;

    #[Inject]
    protected MobileSegmentService $mobileSegmentService;

    /**
     * 虚拟运营商号段的所属运营商；不是虚商号段返回 null。
     */
    public static function virtualOperator(string $segment): ?string
    {
        $three = substr($segment, 0, 3);
        $four = substr($segment, 0, 4);

        return match (true) {
            $three === '162', in_array($four, ['1700', '1701', '1702'], true) => Operator::Ctcc->value,
            $three === '165', in_array($four, ['1703', '1705', '1706'], true) => Operator::Cmcc->value,
            $three === '167', $three === '171', in_array($four, ['1704', '1707', '1708', '1709'], true) => Operator::Cucc->value,
            default => null,
        };
    }

    /**
     * 把一行数据转成 mobile_segments 的一行；跳过的返回原因。
     *
     * @return array{0: null|array{segment: string, operator: string, province: string, city: null|string, is_virtual: bool}, 1: null|string} [行, 跳过原因]
     */
    public static function convert(string $segment, ?string $ispName, ?string $provinceName, ?string $city): array
    {
        $province = Province::fromFullName((string) $provinceName);
        if ($province === null) {
            return [null, '省份认不出：' . ($provinceName ?? 'NULL')];
        }
        $virtualOperator = self::virtualOperator($segment);
        $operator = $virtualOperator ?? (self::OPERATOR_NAMES[(string) $ispName] ?? null);
        if ($operator === null) {
            return [null, '运营商认不出：' . ($ispName ?? 'NULL')];
        }

        return [[
            'segment' => $segment,
            'operator' => $operator,
            'province' => $province,
            'city' => $city === null || $city === '' ? null : mb_substr($city, 0, 32),
            'is_virtual' => $virtualOperator !== null,
        ], null];
    }

    /**
     * @param bool $dryRun 只统计不写库
     * @return array{rows: int, imported: int, virtual: int, unverified: int, deleted: int, by_operator: array<string, int>, skipped: array<string, int>, unparsed: int, unparsed_samples: list<string>}
     */
    public function import(string $path, bool $dryRun = false, ?callable $progress = null): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("打不开文件：{$path}");
        }
        $stats = ['rows' => 0, 'imported' => 0, 'virtual' => 0, 'unverified' => 0, 'deleted' => 0, 'by_operator' => [], 'skipped' => [], 'unparsed' => 0, 'unparsed_samples' => []];
        $batch = [];
        $now = date('Y-m-d H:i:s');
        try {
            while (($line = fgets($handle)) !== false) {
                if (! str_starts_with($line, 'INSERT INTO')) {
                    continue;
                }
                if (preg_match(self::LINE, $line, $m) !== 1) {
                    ++$stats['unparsed'];
                    if (count($stats['unparsed_samples']) < 5) {
                        $stats['unparsed_samples'][] = mb_substr(trim($line), 0, 160);
                    }
                    continue;
                }
                ++$stats['rows'];
                [, $segment, $province, $city, $isp, $deleted, $isOk] = $m;
                if ($deleted === '1') {
                    ++$stats['deleted'];
                    continue;
                }
                [$row, $reason] = self::convert($segment, self::value($isp), self::value($province), self::value($city));
                if ($row === null) {
                    $stats['skipped'][$reason] = ($stats['skipped'][$reason] ?? 0) + 1;
                    continue;
                }
                ++$stats['imported'];
                $stats['by_operator'][$row['operator']] = ($stats['by_operator'][$row['operator']] ?? 0) + 1;
                $stats['virtual'] += $row['is_virtual'] ? 1 : 0;
                $stats['unverified'] += $isOk === '0' ? 1 : 0;

                $batch[] = $row + ['created_at' => $now, 'updated_at' => $now];
                if (count($batch) >= self::BATCH) {
                    $dryRun || $this->mobileSegmentDao->upsertMany($batch);
                    $batch = [];
                    $progress && $progress($stats['imported']);
                }
            }
            if ($batch !== []) {
                $dryRun || $this->mobileSegmentDao->upsertMany($batch);
                $progress && $progress($stats['imported']);
            }
        } finally {
            fclose($handle);
        }
        if (! $dryRun) {
            $this->mobileSegmentService->flushAll();
        }

        return $stats;
    }

    private static function value(string $sql): ?string
    {
        return $sql === 'NULL' ? null : substr($sql, 1, -1);
    }
}
