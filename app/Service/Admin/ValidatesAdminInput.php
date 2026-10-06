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

namespace App\Service\Admin;

use App\Enum\Operator;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 后台表单里反复出现的字段校验，校验不过直接抛 422。
 */
trait ValidatesAdminInput
{
    private function requiredString(mixed $value, string $label, int $max): string
    {
        $value = trim(is_string($value) || is_numeric($value) ? (string) $value : '');
        if ($value === '' || mb_strlen($value) > $max) {
            throw new HttpException(422, "{$label}为 1~{$max} 个字");
        }

        return $value;
    }

    private function remark(mixed $remark): ?string
    {
        $remark = trim(is_string($remark) ? $remark : '');

        return $remark === '' ? null : mb_substr($remark, 0, 255);
    }

    private function status(mixed $status): string
    {
        if (! in_array($status, ['active', 'disabled'], true)) {
            throw new HttpException(422, 'status 只能是 active 或 disabled');
        }

        return $status;
    }

    /**
     * 金额：大于 0，最多两位小数，统一成两位小数的字符串。
     */
    private function money(mixed $value, string $label): string
    {
        $value = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
        if (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $value) !== 1 || bccomp($value, '0', 2) <= 0) {
            throw new HttpException(422, "{$label}必须是大于 0 的金额，最多两位小数");
        }

        return bcadd($value, '0', 2);
    }

    private function faceValue(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value != $value || (int) $value < 1 || (int) $value > 100000) {
            throw new HttpException(422, '面值必须是 1~100000 的整数（元）');
        }

        return (int) $value;
    }

    /**
     * @return list<string> 去重后按 Operator 定义顺序排列
     */
    private function operators(mixed $operators): array
    {
        if (! is_array($operators) || $operators === []) {
            throw new HttpException(422, '请至少选择一个运营商');
        }
        foreach ($operators as $operator) {
            if (! is_string($operator) || Operator::tryFrom($operator) === null) {
                throw new HttpException(422, '未知的运营商：' . (is_string($operator) ? $operator : gettype($operator)));
            }
        }

        return array_values(array_intersect(Operator::values(), $operators));
    }
}
