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

namespace App\Service\Order;

/**
 * 平台订单号：14 位时间 + 8 位随机数，共 22 位。
 *
 * 不用自增序号，免得商户从单号推算出平台的订单量。同一秒内撞号的概率很低，
 * 撞了由 OrderService 换一个号重试（唯一索引兜底）。
 */
class OrderNoGenerator
{
    public function next(): string
    {
        return date('YmdHis') . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
}
