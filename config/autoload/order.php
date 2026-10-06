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
use function Hyperf\Support\env;

/*
 * 订单处理的时间参数，含义见 docs/project.md 2.6。
 */
return [
    // 同一手机号、同一面值，多少分钟内只允许一笔进行中的订单
    'duplicate_window_minutes' => (int) env('ORDER_DUPLICATE_WINDOW_MINUTES', 10),
    // 下单后多少分钟仍没有结果，转为异常订单人工处理
    'abnormal_after_minutes' => (int) env('ORDER_ABNORMAL_AFTER_MINUTES', 120),
    // 提交给供应商多少分钟后还没结果，开始主动查单
    'query_after_minutes' => 5,
    // 主动查单的间隔（分钟）
    'query_interval_minutes' => 5,
    // 通知商户失败后的重试间隔（秒），用完就放弃
    'notify_retry_delays' => [60, 300, 900, 1800, 3600],
    // 开放接口签名的有效期（秒）
    'signature_ttl_seconds' => 300,
];
