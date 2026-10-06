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

use App\Network\HttpClient;
use App\Supplier\CallbackResult;
use App\Supplier\RechargeRequest;
use App\Supplier\RechargeResult;
use App\Supplier\SupplierDriverInterface;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * 上游开放平台的话费接口，文档见 docs/open-api-integration.md（只接话费：下单、查单、回调、余额）。
 *
 * - 我们是它的商户：merchant_order_no 传提交单号 attempt_no，product_id 传供应商商品编码；
 * - 签名：除 sign 外全部参数（空值也算）按参数名升序拼 k=v&...，AppSecret 做 HMAC-SHA256；
 * - 上游不识别运营商，一个上游商品只对应一个运营商，供应商商品要按运营商分开建；
 * - 同一个 merchant_order_no 重复提交只会返回第一次的订单，所以查单查不到时用原单号补提交是安全的。
 */
class OpenPlatformDriver implements SupplierDriverInterface
{
    /** 请求和回调的时间戳允许误差（秒） */
    private const TIMESTAMP_TOLERANCE = 300;

    /** 订单不存在 */
    private const CODE_ORDER_NOT_FOUND = 42005;

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'open_platform';
    }

    public function name(): string
    {
        return '开放平台 API（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口地址', 'type' => 'text', 'required' => true, 'placeholder' => 'https://平台域名/open-api'],
            ['key' => 'app_key', 'label' => 'AppKey', 'type' => 'text', 'required' => true],
            ['key' => 'app_secret', 'label' => 'AppSecret', 'type' => 'secret', 'required' => true],
            ['key' => 'notify_url', 'label' => '回调地址', 'type' => 'text', 'required' => true, 'placeholder' => 'https://我方域名/notify/supplier/供应商编码'],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        return $this->submit($request, true);
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        [$params, $response] = $this->call($request->config, 'GET', '/order', ['merchant_order_no' => $request->attemptNo]);
        $body = $this->decode($response['body']);
        if ($body !== null && (int) $body['code'] === self::CODE_ORDER_NOT_FOUND) {
            // 上游没有这笔单，多半是提交时网络超时没送到；用原单号补提交，就算其实送到了也不会重复下单
            return $this->submit($request, false);
        }

        return $this->toResult($body, $request->supplierOrderNo, $params, $response['body']);
    }

    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        $params = $request->getParsedBody();
        if (! is_array($params) || $params === []) {
            parse_str((string) $request->getBody(), $params);
        }
        $params = array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $params);
        $sign = $params['sign'] ?? '';
        $verified = ($params['app_key'] ?? null) === ($config['app_key'] ?? '')
            && abs(time() - (int) ($params['timestamp'] ?? 0)) <= self::TIMESTAMP_TOLERANCE
            && hash_equals(self::sign($params, $config['app_secret'] ?? ''), $sign)
            && ($params['merchant_order_no'] ?? '') !== '';
        if (! $verified) {
            return new CallbackResult(false, 'fail');
        }
        $result = $this->statusResult($params['status'] ?? '', $params['order_no'] ?? null, $params, null, (string) $request->getBody());

        return new CallbackResult(true, 'success', $params['merchant_order_no'], $params['order_no'] ?? null, $result);
    }

    public function balance(array $config): ?string
    {
        [, $response] = $this->call($config, 'GET', '/balance', []);
        $body = $this->decode($response['body']);
        if ($body === null || (int) $body['code'] !== 0 || ! isset($body['data']['available_balance'])) {
            throw new RuntimeException('查询余额失败：' . mb_substr($response['body'], 0, 200));
        }

        return (string) $body['data']['available_balance'];
    }

    /**
     * 按上游文档 3.1 计算签名，请求签名和回调验签共用。
     *
     * @param array<string, string> $params
     */
    public static function sign(array $params, string $secret): string
    {
        unset($params['sign']);
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return hash_hmac('sha256', implode('&', $pairs), $secret);
    }

    /**
     * 提交话费下单。
     *
     * @param bool $trustRejection 上游拒单时是否判失败。查单补提交时传 false：上游先做什么校验我们不知道，
     *                             万一原单其实存在、只是商品已下架之类被拒，判失败会换供应商重复充值，所以只认 code=0
     */
    private function submit(RechargeRequest $request, bool $trustRejection): RechargeResult
    {
        [$params, $response] = $this->call($request->config, 'POST', '/orders/recharge', [
            'merchant_order_no' => $request->attemptNo,
            'product_id' => $request->externalCode,
            'recharge_account' => $request->mobile,
            'callback_url' => $request->config['notify_url'] ?? '',
        ], 10.0);
        $body = $this->decode($response['body']);
        if (! $trustRejection && ($body === null || (int) $body['code'] !== 0)) {
            return RechargeResult::processing($request->supplierOrderNo, '补提交未受理，等待人工核对：' . $this->describe($body, $response), $this->encode($params), $response['body']);
        }

        return $this->toResult($body, $request->supplierOrderNo, $params, $response['body'], $response['status']);
    }

    /**
     * 把下单 / 查单的返回转成结果。只有上游明确拒绝（没有生成订单）才判失败，拿不准的一律处理中。
     *
     * @param null|array{code: mixed, message?: mixed, data?: mixed} $body
     * @param array<string, string> $params
     */
    private function toResult(?array $body, ?string $supplierOrderNo, array $params, string $raw, int $httpStatus = 200): RechargeResult
    {
        if ($body === null) {
            return RechargeResult::processing($supplierOrderNo, "上游返回无法解析（HTTP {$httpStatus}）", $this->encode($params), $raw);
        }
        $code = (int) $body['code'];
        if ($code === 0) {
            $data = is_array($body['data'] ?? null) ? $body['data'] : [];

            return $this->statusResult((string) ($data['status'] ?? ''), $data['order_no'] ?? $supplierOrderNo, $data, $params, $raw);
        }
        if ($this->isRejection($code)) {
            return RechargeResult::failed('上游拒单：' . $this->describe($body, []), $supplierOrderNo, $this->encode($params), $raw);
        }

        return RechargeResult::processing($supplierOrderNo, '上游返回：' . $this->describe($body, []), $this->encode($params), $raw);
    }

    /**
     * 上游订单状态 → 我们的结果。cancelled / refunded 都按失败处理：refunded 是成功后被上游撤销，
     * 我们的订单已经成功的话不会自动改，OrderProcessService 会记日志等人工冲正。
     *
     * @param array<string, mixed> $data 订单对象或回调参数，取 fail_code / fail_reason
     * @param null|array<string, string> $params
     */
    private function statusResult(string $status, mixed $orderNo, array $data, ?array $params, string $raw): RechargeResult
    {
        $orderNo = is_scalar($orderNo) && $orderNo !== '' ? (string) $orderNo : null;
        $request = $params === null ? null : $this->encode($params);
        $failCode = is_scalar($data['fail_code'] ?? null) ? (string) $data['fail_code'] : '';
        $reason = trim(($failCode === '' ? '' : "[{$failCode}] ") . (is_scalar($data['fail_reason'] ?? null) ? (string) $data['fail_reason'] : ''));

        return match ($status) {
            'success' => RechargeResult::success($orderNo, null, $request, $raw),
            'failed' => RechargeResult::failed($reason === '' ? '上游充值失败' : $reason, $orderNo, $request, $raw),
            'cancelled' => RechargeResult::failed('上游已取消', $orderNo, $request, $raw),
            'refunded' => RechargeResult::failed('上游已退款（成功后被撤销）', $orderNo, $request, $raw),
            default => RechargeResult::processing($orderNo, null, $request, $raw),
        };
    }

    /**
     * 鉴权、参数、业务校验类错误都在建单之前被拒，可以放心换下一家；50000 系统繁忙、49003 等拿不准的不算。
     */
    private function isRejection(int $code): bool
    {
        return ($code >= 40001 && $code <= 42999) || in_array($code, [49001, 49002], true);
    }

    /**
     * 带上公共参数和签名发请求。
     *
     * @param array<string, string> $config
     * @param array<string, string> $business
     * @return array{0: array<string, string>, 1: array{status: int, body: string}} 实际发送的参数（不含 sign）、响应
     */
    private function call(array $config, string $method, string $path, array $business, float $timeout = 5.0): array
    {
        $params = $business + [
            'app_key' => $config['app_key'] ?? '',
            'timestamp' => (string) time(),
            'nonce' => bin2hex(random_bytes(8)),
        ];
        $signed = $params + ['sign' => self::sign($params, $config['app_secret'] ?? '')];
        $url = rtrim($config['api_url'] ?? '', '/') . $path;
        $response = $method === 'GET' ? $this->http->get($url, $signed, $timeout) : $this->http->postForm($url, $signed, $timeout);

        return [$params, $response];
    }

    /**
     * @return null|array{code: mixed, message?: mixed, data?: mixed}
     */
    private function decode(string $raw): ?array
    {
        $body = json_decode($raw, true);

        return is_array($body) && isset($body['code']) && is_numeric($body['code']) ? $body : null;
    }

    /**
     * @param null|array{code: mixed, message?: mixed} $body
     * @param array{status?: int, body?: string} $response
     */
    private function describe(?array $body, array $response): string
    {
        if ($body === null) {
            return 'HTTP ' . ($response['status'] ?? '-') . ' ' . mb_substr($response['body'] ?? '', 0, 100);
        }

        return '[' . $body['code'] . '] ' . (is_scalar($body['message'] ?? null) ? (string) $body['message'] : '');
    }

    /**
     * @param array<string, string> $params
     */
    private function encode(array $params): string
    {
        return (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
