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

use App\Supplier\CallbackResult;
use App\Supplier\RechargeRequest;
use App\Supplier\RechargeResult;
use App\Supplier\RechargeStatus;
use App\Supplier\SupplierDriverInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Swoole\Coroutine;

/**
 * 模拟供应商，开发和测试用，不发任何外部请求。
 *
 * - 充值、查单按接口参数里的「模拟结果」返回；选「超时」时充值直接抛异常（模拟网络超时），查单返回处理中；
 * - 回调：POST JSON {"attempt_no": "...", "status": "success|failed", "message": "..."}，回 ok。
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
                    ['value' => 'processing', 'label' => '一直处理中（等回调）'],
                    ['value' => 'timeout', 'label' => '超时'],
                    ['value' => 'slow', 'label' => '1 秒后成功（压测用，模拟真实供应商耗时）'],
                ],
            ],
            ['key' => 'api_url', 'label' => '接口地址', 'type' => 'text', 'required' => false],
            ['key' => 'secret', 'label' => '密钥', 'type' => 'secret', 'required' => false],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        $raw = json_encode(['order_no' => $request->attemptNo, 'mobile' => $request->mobile, 'product' => $request->externalCode]);

        return match ($request->config['result'] ?? 'success') {
            'failed' => RechargeResult::failed('模拟失败', null, $raw, '{"code":"FAIL"}'),
            'processing' => RechargeResult::processing('MOCK' . $request->attemptNo, '已受理', $raw, '{"code":"ACCEPTED"}'),
            'timeout' => throw new RuntimeException('模拟超时'),
            'slow' => $this->slowSuccess($request, $raw),
            default => RechargeResult::success('MOCK' . $request->attemptNo, null, $raw, '{"code":"SUCCESS"}'),
        };
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        return match ($request->config['result'] ?? 'success') {
            'failed' => RechargeResult::failed('模拟失败', $request->supplierOrderNo),
            'success', 'slow' => RechargeResult::success($request->supplierOrderNo),
            default => RechargeResult::processing($request->supplierOrderNo),
        };
    }

    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        $body = json_decode((string) $request->getBody(), true);
        $status = RechargeStatus::tryFrom(is_array($body) ? (string) ($body['status'] ?? '') : '');
        if ($status === null || $status === RechargeStatus::Processing || ! is_string($body['attempt_no'] ?? null)) {
            return new CallbackResult(false, 'invalid');
        }
        $message = is_string($body['message'] ?? null) ? $body['message'] : null;

        return new CallbackResult(true, 'ok', $body['attempt_no'], null, new RechargeResult($status, null, $message, null, (string) $request->getBody()));
    }

    public function balance(array $config): ?string
    {
        return '99999.00';
    }

    /**
     * 协程里 sleep 不占 CPU，正好模拟等供应商接口返回。
     */
    private function slowSuccess(RechargeRequest $request, string $raw): RechargeResult
    {
        Coroutine::sleep(1.0);

        return RechargeResult::success('MOCK' . $request->attemptNo, null, $raw, '{"code":"SUCCESS"}');
    }
}
