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
use Hyperf\AsyncQueue\Driver\RedisDriver;

use function Hyperf\Support\env;

/*
 * 两个队列分开消费，互不影响：
 * - default：提交订单给供应商（同步等供应商返回，一个任务几秒），吞吐量 = 进程数 × 并发数 ÷ 供应商平均耗时；
 * - notify：通知商户（商户接口可能很慢、一直失败重试），单独一个队列，不占提交订单的并发。
 *
 * 注意：协程里取到的数据库连接要到协程结束才归还连接池，一个提交任务在等供应商的几秒里一直占着一个连接，
 * 所以 QUEUE_CONCURRENCY 不能超过 DB_MAX_CONNECTIONS（见 databases.php）。
 */
return [
    'default' => [
        'driver' => RedisDriver::class,
        'redis' => [
            'pool' => 'default',
        ],
        'channel' => '{queue}',
        'timeout' => 2,
        'retry_seconds' => 5,
        // 一个订单可能连续试几家供应商，每家最多几秒，留足余量
        'handle_timeout' => 60,
        'processes' => (int) env('QUEUE_PROCESSES', 2),
        'concurrent' => [
            'limit' => (int) env('QUEUE_CONCURRENCY', 50),
        ],
        'max_messages' => 0,
    ],
    'notify' => [
        'driver' => RedisDriver::class,
        'redis' => [
            'pool' => 'default',
        ],
        'channel' => '{queue:notify}',
        'timeout' => 2,
        'retry_seconds' => 5,
        // 一次通知最多等商户 5 秒
        'handle_timeout' => 15,
        'processes' => 1,
        'concurrent' => [
            'limit' => (int) env('NOTIFY_QUEUE_CONCURRENCY', 30),
        ],
        'max_messages' => 0,
    ],
];
