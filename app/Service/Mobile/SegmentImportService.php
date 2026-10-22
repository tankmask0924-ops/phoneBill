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
use Generator;
use Hyperf\Di\Annotation\Inject;
use RuntimeException;
use XMLReader;

/**
 * 号段库导入：读 Navicat 导出的 recharge_phone_prefix_info 表（每行一条 INSERT），或者 xlsx 号段表（比如 t_sys_phone.xlsx），
 * 写入 mobile_segments：默认按号段覆盖；只新增模式下库里已有的号段不动。
 *
 * 转换规则（docs/project.md 2.3）：
 * - 162 / 165 / 167 / 170 / 171 是虚拟运营商专用号段，不管数据里标的是什么，一律标为虚拟运营商（下单拒绝），
 *   所属运营商按号段分配规则判断；数据源对这几个号段的运营商标注不可靠；
 * - 其他号段：中国移动 / 移动 → cmcc、中国联通 / 联通 → cucc、中国电信 / 电信 → ctcc、中国广电 → cbn；
 * - 省份全称转简称；认不出的运营商、省份跳过并计数；
 * - 「未校验」（is_ok = 0）的照样导入，不导入的话这些号码会因为查不到号段被拒单；xlsx 没有这个字段。
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
     * @param string $path .sql（Navicat 导出）或 .xlsx（第一个工作表，表头含 号段 / 省区 / 城市 / 服务商）
     * @param bool $dryRun 只统计不写库
     * @param bool $onlyNew 只新增库里没有的号段，已有的不动
     * @return array{rows: int, imported: int, existing: int, virtual: int, unverified: int, deleted: int, by_operator: array<string, int>, skipped: array<string, int>, unparsed: int, unparsed_samples: list<string>}
     */
    public function import(string $path, bool $dryRun = false, ?callable $progress = null, bool $onlyNew = false): array
    {
        $stats = ['rows' => 0, 'imported' => 0, 'existing' => 0, 'virtual' => 0, 'unverified' => 0, 'deleted' => 0, 'by_operator' => [], 'skipped' => [], 'unparsed' => 0, 'unparsed_samples' => []];
        $records = str_ends_with(strtolower($path), '.xlsx') ? $this->xlsxRecords($path, $stats) : $this->sqlRecords($path, $stats);
        $batch = [];
        foreach ($records as [$segment, $province, $city, $isp, $unverified]) {
            ++$stats['rows'];
            [$row, $reason] = self::convert($segment, $isp, $province, $city);
            if ($row === null) {
                $stats['skipped'][$reason] = ($stats['skipped'][$reason] ?? 0) + 1;
                continue;
            }
            $batch[] = [$row, $unverified];
            if (count($batch) >= self::BATCH) {
                $this->flush($batch, $dryRun, $onlyNew, $stats);
                $batch = [];
                $progress && $progress($stats['rows']);
            }
        }
        if ($batch !== []) {
            $this->flush($batch, $dryRun, $onlyNew, $stats);
            $progress && $progress($stats['rows']);
        }
        if (! $dryRun) {
            // 只新增时也要清：之前查不到的号段可能缓存了「不存在」
            $this->mobileSegmentService->flushAll();
        }

        return $stats;
    }

    /**
     * 写一批并计数；只新增时先去掉库里已有的号段。
     *
     * @param list<array{0: array<string, mixed>, 1: bool}> $batch [行, 是否未校验]
     * @param array<string, mixed> $stats
     */
    private function flush(array $batch, bool $dryRun, bool $onlyNew, array &$stats): void
    {
        if ($onlyNew) {
            $existing = array_flip($this->mobileSegmentDao->existingSegments(array_map(static fn (array $item) => $item[0]['segment'], $batch)));
            $count = count($batch);
            $batch = array_filter($batch, static fn (array $item) => ! isset($existing[$item[0]['segment']]));
            $stats['existing'] += $count - count($batch);
        }
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($batch as [$row, $unverified]) {
            ++$stats['imported'];
            $stats['by_operator'][$row['operator']] = ($stats['by_operator'][$row['operator']] ?? 0) + 1;
            $stats['virtual'] += $row['is_virtual'] ? 1 : 0;
            $stats['unverified'] += $unverified ? 1 : 0;
            $rows[] = $row + ['created_at' => $now, 'updated_at' => $now];
        }
        if (! $dryRun && $rows !== []) {
            $onlyNew ? $this->mobileSegmentDao->insertMissing($rows) : $this->mobileSegmentDao->upsertMany($rows);
        }
    }

    /**
     * Navicat 导出的 SQL：每行一条 INSERT；已删除的、格式认不出的记进 $stats。
     *
     * @param array<string, mixed> $stats
     * @return Generator<array{0: string, 1: null|string, 2: null|string, 3: null|string, 4: bool}> [号段, 省份, 城市, 运营商, 是否未校验]
     */
    private function sqlRecords(string $path, array &$stats): Generator
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("打不开文件：{$path}");
        }
        try {
            while (($line = fgets($handle)) !== false) {
                if (! str_starts_with($line, 'INSERT INTO')) {
                    continue;
                }
                if (preg_match(self::LINE, $line, $m) !== 1) {
                    $this->unparsed($stats, $line);
                    continue;
                }
                [, $segment, $province, $city, $isp, $deleted, $isOk] = $m;
                if ($deleted === '1') {
                    ++$stats['rows'];
                    ++$stats['deleted'];
                    continue;
                }
                yield [$segment, self::value($province), self::value($city), self::value($isp), $isOk === '0'];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * xlsx 第一个工作表：第一行是表头，按列名找 号段 / 省区 / 城市 / 服务商（省份、运营商也认）。
     * 用 XMLReader 流式读，50 多万行也不用整表载入。
     *
     * @param array<string, mixed> $stats
     * @return Generator<array{0: string, 1: null|string, 2: null|string, 3: null|string, 4: bool}>
     */
    private function xlsxRecords(string $path, array &$stats): Generator
    {
        if (! is_file($path)) {
            throw new RuntimeException("打不开文件：{$path}");
        }
        $shared = $this->xlsxSharedStrings($path);
        $columns = null;
        foreach ($this->xlsxRows($path, 'xl/worksheets/sheet1.xml', $shared) as $cells) {
            if ($columns === null) {
                $columns = self::xlsxColumns($cells);
                continue;
            }
            $segment = trim($cells[$columns['segment']] ?? '');
            if (preg_match('/^\d{7}$/', $segment) !== 1) {
                $this->unparsed($stats, implode(',', $cells));
                continue;
            }
            $cell = static fn (string $key) => ($value = trim($cells[$columns[$key]] ?? '')) === '' ? null : $value;
            yield [$segment, $cell('province'), $cell('city'), $cell('isp'), false];
        }
    }

    /**
     * @param array<int, string> $header
     * @return array{segment: int, province: int, city: int, isp: int}
     */
    private static function xlsxColumns(array $header): array
    {
        $names = ['segment' => ['号段'], 'province' => ['省区', '省份'], 'city' => ['城市'], 'isp' => ['服务商', '运营商']];
        $columns = [];
        foreach ($names as $key => $candidates) {
            foreach ($header as $index => $title) {
                if (in_array(trim($title), $candidates, true)) {
                    $columns[$key] = $index;
                    break;
                }
            }
            if (! isset($columns[$key])) {
                throw new RuntimeException('表头里没找到「' . implode(' / ', $candidates) . '」列');
            }
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function xlsxSharedStrings(string $path): array
    {
        $reader = new XMLReader();
        // 没有共享字符串表的文件（全是数字）
        if (! @$reader->open('zip://' . $path . '#xl/sharedStrings.xml')) {
            return [];
        }
        $strings = [];
        $text = '';
        $phonetic = false;
        try {
            while ($reader->read()) {
                $name = $reader->localName;
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    if ($name === 'si') {
                        $text = '';
                        if ($reader->isEmptyElement) {
                            $strings[] = '';
                        }
                    } elseif ($name === 'rPh') {
                        // 拼音注音不算正文
                        $phonetic = ! $reader->isEmptyElement;
                    } elseif ($name === 't' && ! $phonetic) {
                        $text .= $reader->readString();
                    }
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                    if ($name === 'si') {
                        $strings[] = $text;
                    } elseif ($name === 'rPh') {
                        $phonetic = false;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        return $strings;
    }

    /**
     * @param list<string> $shared
     * @return Generator<array<int, string>> 每行按列号（从 0 开始）
     */
    private function xlsxRows(string $path, string $sheet, array $shared): Generator
    {
        $reader = new XMLReader();
        if (! @$reader->open('zip://' . $path . '#' . $sheet)) {
            throw new RuntimeException("不是有效的 xlsx 文件：{$path}");
        }
        $row = [];
        $column = 0;
        $type = null;
        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    if ($reader->localName === 'row') {
                        $row = [];
                        if ($reader->isEmptyElement) {
                            yield $row;
                        }
                    } elseif ($reader->localName === 'c') {
                        $column = self::columnIndex((string) $reader->getAttribute('r'));
                        $type = $reader->getAttribute('t');
                    } elseif ($reader->localName === 'v') {
                        $value = $reader->readString();
                        $row[$column] = $type === 's' ? ($shared[(int) $value] ?? '') : $value;
                    } elseif ($reader->localName === 't' && $type === 'inlineStr') {
                        $row[$column] = ($row[$column] ?? '') . $reader->readString();
                    }
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row') {
                    yield $row;
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * 单元格引用的列号：A1 → 0，AB12 → 27。
     */
    private static function columnIndex(string $reference): int
    {
        $index = 0;
        foreach (str_split(preg_replace('/\d+$/', '', $reference) ?: 'A') as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        return $index - 1;
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function unparsed(array &$stats, string $line): void
    {
        ++$stats['unparsed'];
        if (count($stats['unparsed_samples']) < 5) {
            $stats['unparsed_samples'][] = mb_substr(trim($line), 0, 160);
        }
    }

    private static function value(string $sql): ?string
    {
        return $sql === 'NULL' ? null : substr($sql, 1, -1);
    }
}
