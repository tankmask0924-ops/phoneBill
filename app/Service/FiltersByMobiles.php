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
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 订单列表按手机号筛选，支持一次查多个：换行、空格、逗号、分号、顿号（中英文）分隔都行，最多 200 个。
 */
trait FiltersByMobiles
{
    /**
     * @return bool 是否带了手机号条件（带了就不加默认日期范围）
     */
    private function applyMobileFilter(Builder $builder, mixed $value): bool
    {
        $raw = trim(is_string($value) || is_int($value) ? (string) $value : '');
        if ($raw === '') {
            return false;
        }
        $items = preg_split('/[\s,，;；、]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $mobiles = array_values(array_unique(array_filter($items, static fn (string $m) => preg_match('/^\d{1,11}$/', $m) === 1)));
        if (count($mobiles) > 200) {
            throw new HttpException(422, '一次最多查 200 个手机号');
        }
        if ($mobiles === []) {
            // 填了但没有一个像手机号：查不到，而不是当成没填
            $builder->whereRaw('1 = 0');
        } elseif (count($mobiles) === 1) {
            $builder->where('mobile', $mobiles[0]);
        } else {
            $builder->whereIn('mobile', $mobiles);
        }

        return true;
    }
}
