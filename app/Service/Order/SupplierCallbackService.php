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
use App\Service\AbstractService;
use App\Service\Supplier\SupplierGateway;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 供应商结果回调：/notify/supplier/{供应商编码}，由对应驱动验签、解析，再交给 OrderProcessService。
 */
class SupplierCallbackService extends AbstractService
{
    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected OrderAttemptDao $attemptDao;

    #[Inject]
    protected SupplierGateway $gateway;

    #[Inject]
    protected OrderProcessService $processService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @return array{status: int, body: string} 回给供应商的 HTTP 状态码和响应体
     */
    public function handle(string $supplierCode, ServerRequestInterface $request): array
    {
        $supplier = $this->supplierDao->newQuery()->where('code', $supplierCode)->first();
        if ($supplier === null) {
            return ['status' => 404, 'body' => 'unknown supplier'];
        }
        $callback = $this->gateway->parseCallback($supplier, $request);
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
        $this->processService->applyResult($attempt->id, $callback->result);

        return ['status' => 200, 'body' => $callback->reply];
    }
}
