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

use Psr\Http\Message\ServerRequestInterface;

/**
 * 供应商对接驱动：每接一家供应商写一个实现类，并登记到 SupplierDriverRegistry::DRIVERS。
 *
 * 约定：
 * - 只有供应商明确说成功 / 失败才返回 Success / Failed，拿不准一律 Processing，不能把超时当失败（会重复充值）；
 * - 网络异常、超时可以直接抛异常，调用方按处理中对待，之后靠回调或查单拿结果；
 * - 每次调用要在 10 秒内返回（一个订单可能连续试几家，队列任务总时限 60 秒）。
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
     * placeholder：可选，输入框里的填写提示
     *
     * @return list<array{key: string, label: string, type: string, required: bool, placeholder?: string, options?: list<array{value: string, label: string}>}>
     */
    public function configSchema(): array;

    /**
     * 提交充值。
     */
    public function recharge(RechargeRequest $request): RechargeResult;

    /**
     * 主动查单。
     */
    public function query(RechargeRequest $request): RechargeResult;

    /**
     * 验签并解析供应商的结果回调。
     *
     * @param array<string, string> $config
     */
    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult;

    /**
     * 查询我们在供应商那边的余额，不支持返回 null。
     *
     * @param array<string, string> $config
     */
    public function balance(array $config): ?string;
}
