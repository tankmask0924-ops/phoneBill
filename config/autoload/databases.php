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

return [
    'default' => [
        'driver' => env('DB_DRIVER', 'mysql'),
        'host' => env('DB_HOST', 'localhost'),
        'database' => env('DB_DATABASE', 'phone_bill'),
        'port' => env('DB_PORT', 3306),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
        'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'prefix' => env('DB_PREFIX', ''),
        'options' => [
            // 原生预处理每条 SQL 要走 prepare/execute/close 三个包，Swoole 协程下明显变慢；
            // 模拟预处理只走一个包，PHP 8.1+ 的 mysqlnd 在模拟模式下 int/float 仍返回原生类型，参数照样转义。
            PDO::ATTR_EMULATE_PREPARES => true,
        ],
        // 每个进程一个连接池（HTTP worker、队列消费、定时任务各自独立）。
        // 部署时 MySQL 的 max_connections 要大于「进程数 × DB_MAX_CONNECTIONS」的实际峰值，见 README。
        'pool' => [
            'min_connections' => 1,
            'max_connections' => (int) env('DB_MAX_CONNECTIONS', 64),
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => (float) env('DB_MAX_IDLE_TIME', 60),
        ],
        'commands' => [
            'gen:model' => [
                'path' => 'app/Model',
                'force_casts' => true,
                'inheritance' => 'Model',
            ],
        ],
    ],
];
