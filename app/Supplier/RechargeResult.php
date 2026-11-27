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
 * 驱动返回的结果。request / response 是原始报文，存进 order_attempts 方便排查，密钥要先打码。
 */
final class RechargeResult
{
    public function __construct(
        public readonly RechargeStatus $status,
        public readonly ?string $supplierOrderNo = null,
        public readonly ?string $message = null,
        public readonly ?string $request = null,
        public readonly ?string $response = null,
        /** 调供应商接口时出了异常（连不上、超时、握手失败等）或上游回了不确定的结果，不知道上游建没建单 */
        public readonly bool $callFailed = false,
        /** 这次调用最后一个 HTTP 请求的状态码，0 = 没拿到响应（连不上、超时），null = 没发 HTTP 请求；由 SupplierGateway 填 */
        public readonly ?int $httpStatus = null,
        /**
         * 查单时上游明确回复查不到这笔订单（按我们的提交单号查的）。状态照各家规则（多数只记录、按处理中），
         * 异常订单人工查单时据此直接置失败并通知商户（OrderProcessService::queryAttempt）。
         */
        public readonly bool $notFound = false,
    ) {
    }

    public function withHttpStatus(?int $httpStatus): self
    {
        return new self($this->status, $this->supplierOrderNo, $this->message, $this->request, $this->response, $this->callFailed, $httpStatus, $this->notFound);
    }

    /**
     * 标记为「上游查不到这笔订单」，状态不变。
     */
    public function asNotFound(): self
    {
        return new self($this->status, $this->supplierOrderNo, $this->message, $this->request, $this->response, $this->callFailed, $this->httpStatus, true);
    }

    public static function success(?string $supplierOrderNo = null, ?string $message = null, ?string $request = null, ?string $response = null): self
    {
        return new self(RechargeStatus::Success, $supplierOrderNo, $message, $request, $response);
    }

    public static function failed(string $message, ?string $supplierOrderNo = null, ?string $request = null, ?string $response = null): self
    {
        return new self(RechargeStatus::Failed, $supplierOrderNo, $message, $request, $response);
    }

    public static function processing(?string $supplierOrderNo = null, ?string $message = null, ?string $request = null, ?string $response = null): self
    {
        return new self(RechargeStatus::Processing, $supplierOrderNo, $message, $request, $response);
    }

    /**
     * 调用异常：按处理中算（上游可能已经建单），由调用方决定是否转人工。
     * 上游明确回了「不确定是否建单」（比如系统繁忙）时，驱动也可以返回它，带上原始报文。
     */
    public static function callFailed(string $message, ?string $request = null, ?string $response = null): self
    {
        return new self(RechargeStatus::Processing, null, $message, $request, $response, true);
    }
}
