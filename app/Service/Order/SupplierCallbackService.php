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

use App\Dao\OrderAttemptDao;
use App\Dao\SupplierDao;
use App\Model\OrderAttempt;
use App\Model\Supplier;
use App\Service\AbstractService;
use App\Service\Supplier\SupplierGateway;
use App\Supplier\CallbackResult;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Logger\LoggerFactory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 供应商结果回调：/notify/supplier/{供应商编码}，由对应驱动验签、解析，再交给 OrderProcessService。
 *
 * 回调后的确认查单没拿到明确结果（Turbo、商腾的回调不带签名，刘浩的签名不含状态，都要再查单确认）：
 * 原因和查单报文记到这次提交的查单记录上，再按 RETRY_DELAYS 把这条回调重放几次重新确认；确认到明确结果
 * 和正常回调一样处理（人工处理单也交回正常流程）。提交已经有结果的不再重试。
 */
class SupplierCallbackService extends AbstractService
{
    /** 第 1、2、3 次重新确认分别在上一次之后多少秒 */
    public const RETRY_DELAYS = [30, 60, 120];

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected OrderAttemptDao $attemptDao;

    #[Inject]
    protected SupplierGateway $gateway;

    #[Inject]
    protected OrderProcessService $processService;

    #[Inject]
    protected OrderDispatcher $dispatcher;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @return array{status: int, body: string} 回给供应商的 HTTP 状态码和响应体
     */
    public function handle(string $supplierCode, ServerRequestInterface $request, int $retry = 0): array
    {
        // 已删除的供应商也照常处理迟到的回调
        $supplier = $this->supplierDao->findByCodeWithTrashed($supplierCode);
        if ($supplier === null) {
            return ['status' => 404, 'body' => 'unknown supplier'];
        }
        $callback = $this->gateway->parseCallback($supplier, $request);
        if ($callback->unconfirmed !== null) {
            $this->unconfirmed($supplier, $callback, $request, $retry);
        }
        if (! $callback->verified || $callback->result === null) {
            return ['status' => 400, 'body' => $callback->reply];
        }
        $attempt = $this->attemptDao->findForSupplier($supplier->id, $callback->attemptNo, $callback->supplierOrderNo);
        if ($attempt === null) {
            // 找不到也照常应答，免得供应商一直重推；记日志人工核对
            $this->loggerFactory->get('supplier')->warning('回调找不到对应的提交', [
                'supplier' => $supplier->code,
                'attempt_no' => $callback->attemptNo,
                'supplier_order_no' => $callback->supplierOrderNo,
            ]);

            return ['status' => 200, 'body' => $callback->reply];
        }
        if ($retry > 0) {
            // 重新确认拿到了结果：这次查单也记上，盖掉之前「没拿到明确结果」的记录
            $this->processService->recordQuery($attempt->id, $callback->result, '回调后确认查单：');
        }
        $this->processService->applyCallback($attempt->id, $callback->result);

        return ['status' => 200, 'body' => $callback->reply];
    }

    /**
     * 重放一条回调重新确认（ConfirmSupplierCallbackJob）。
     *
     * @param array<string, mixed> $query
     */
    public function replay(string $supplierCode, string $body, string $contentType, array $query, int $retry): void
    {
        $request = (new Request('POST', "/notify/supplier/{$supplierCode}", $contentType === '' ? [] : ['Content-Type' => $contentType], new SwooleStream($body)))
            ->withQueryParams($query);
        $parsed = json_decode($body, true);
        if (! is_array($parsed)) {
            parse_str($body, $parsed);
        }
        $this->handle($supplierCode, $request->withParsedBody($parsed), $retry);
    }

    /**
     * 确认查单没拿到明确结果：记到这次提交的查单记录上；提交还在处理中、没重试满就过一会再确认。
     */
    private function unconfirmed(Supplier $supplier, CallbackResult $callback, ServerRequestInterface $request, int $retry): void
    {
        $attempt = $this->attemptDao->findForSupplier($supplier->id, $callback->attemptNo, $callback->supplierOrderNo);
        if ($attempt === null || $attempt->status !== OrderAttempt::STATUS_PROCESSING) {
            return;
        }
        $this->processService->recordQuery($attempt->id, $callback->unconfirmed, '回调后确认查单：');
        $context = ['supplier' => $supplier->code, 'attempt_no' => $attempt->attempt_no, 'retry' => $retry, 'message' => $callback->unconfirmed->message];
        if ($retry >= count(self::RETRY_DELAYS)) {
            $this->loggerFactory->get('supplier')->warning('回调后确认查单一直没拿到明确结果，不再重试，等定时查单', $context);

            return;
        }
        $this->loggerFactory->get('supplier')->warning('回调后确认查单没拿到明确结果，稍后重新确认', $context + ['delay' => self::RETRY_DELAYS[$retry]]);
        $this->dispatcher->confirmCallback(
            $supplier->code,
            (string) $request->getBody(),
            $request->getHeaderLine('Content-Type'),
            $request->getQueryParams(),
            $retry + 1,
            self::RETRY_DELAYS[$retry],
        );
    }
}
