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
 * 供应商对接驱动：每接一家供应商写一个实现类，并登记到 SupplierDriverRegistry::DRIVERS。
 *
 * 目前只有声明接口参数的部分；充值、查单、解析回调、查余额在下单主流程（第二阶段）里补上。
 */
interface SupplierDriverInterface
{
    /**
     * 驱动编码，存在 suppliers.driver，上线后不能改。
     */
    public function code(): string;

    /**
     * 后台下拉框里显示的名字。
     */
    public function name(): string;

    /**
     * 这家供应商需要填的接口参数，后台按它渲染表单、校验和打码。
     *
     * type：text 普通文本 / secret 密钥（加密存储，接口返回时不回显）/ select 下拉（options 为可选值）
     *
     * @return list<array{key: string, label: string, type: string, required: bool, options?: list<array{value: string, label: string}>}>
     */
    public function configSchema(): array;
}
