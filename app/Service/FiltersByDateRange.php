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

namespace App\Service;

use Hyperf\Database\Model\Builder;

/**
 * 订单、流水这类大表的列表按 created_at 筛日期范围（created_from / created_to，格式 Y-m-d）。
 *
 * 没传开始日期、又不是按单号 / 手机号精确查找时，默认只查最近 7 天：
 * 表每天几十万行，不限时间的分页和计数会越来越慢。
 */
trait FiltersByDateRange
{
    private int $defaultRecentDays = 7;

    /**
     * @param array<string, mixed> $query
     * @param bool $exactSearch 是否按单号、手机号等精确条件查找（这时不加默认范围）
     */
    private function applyDateRange(Builder $builder, array $query, bool $exactSearch = false): void
    {
        $from = $this->dateParam($query['created_from'] ?? null);
        $to = $this->dateParam($query['created_to'] ?? null);
        if ($from === null && ! $exactSearch) {
            $from = date('Y-m-d', strtotime('-' . ($this->defaultRecentDays - 1) . ' days'));
        }
        if ($from !== null) {
            $builder->where('created_at', '>=', $from . ' 00:00:00');
        }
        if ($to !== null) {
            $builder->where('created_at', '<=', $to . ' 23:59:59');
        }
    }

    private function dateParam(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
