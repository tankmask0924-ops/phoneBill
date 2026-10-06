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

namespace App\Supplier\Driver;

use App\Supplier\SupplierDriverInterface;

/**
 * 模拟供应商，开发和测试用，不发任何外部请求。按接口参数里的「模拟结果」返回。
 */
class MockDriver implements SupplierDriverInterface
{
    public function code(): string
    {
        return 'mock';
    }

    public function name(): string
    {
        return '模拟供应商（开发测试用）';
    }

    public function configSchema(): array
    {
        return [
            [
                'key' => 'result',
                'label' => '模拟结果',
                'type' => 'select',
                'required' => true,
                'options' => [
                    ['value' => 'success', 'label' => '成功'],
                    ['value' => 'failed', 'label' => '失败'],
                    ['value' => 'processing', 'label' => '一直处理中'],
                ],
            ],
            ['key' => 'api_url', 'label' => '接口地址', 'type' => 'text', 'required' => false],
            ['key' => 'secret', 'label' => '密钥', 'type' => 'secret', 'required' => false],
        ];
    }
}
