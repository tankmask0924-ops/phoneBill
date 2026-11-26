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
    // 模拟供应商驱动（MockDriver，按配置固定返回成功 / 失败，不真的充值）只给本地开发和测试用。
    // 线上必须关着：开着的话，拿到后台权限的人可以建一个「永远成功」的供应商。
    'mock_enabled' => (bool) env('SUPPLIER_MOCK_ENABLED', false),
];
