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

namespace App\Supplier;

/**
 * 供应商对一次充值的结论。只有明确的成功 / 失败才算数，其他一律当处理中。
 */
enum RechargeStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Processing = 'processing';
}
