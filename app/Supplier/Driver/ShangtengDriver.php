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
use Throwable;

/**
 * 商腾科技话费接口，文档见 docs/API接口.md（下单、查单、回调、余额）。
 *
 * - external_orderno 传提交单号 attempt_no，product_id 传供应商商品编码；
 * - 签名：参数按名升序转 JSON（不转义斜杠和中文），sha1(秒级时间戳 + JSON + ApiKey)，放在 Sign / Timestamp / Userid 请求头；
 * - 回调不带签名，不能信：只取订单号，结果以主动查单为准；
 * - 下单返回 status 非 200 表示没建单（已和上游确认），直接失败；没拿到可解析的返回时不知道建没建单，按处理中等查单；
 * - 文档没说查不到订单时返回什么，所以查单只认明确状态，其余一律处理中，到时转异常人工处理。
 */
class ShangtengDriver implements SupplierDriverInterface
{
    private const STATUS_OK = 200;

    /** 下单时传了会让上游校验面值的取值，其他面值不传 */
    private const CHECKED_FACE_VALUES = [50, 100, 200, 300, 500];

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'shangteng';
    }

    public function name(): string
    {
        return '商腾科技 API（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口地址', 'type' => 'text', 'required' => true, 'placeholder' => 'https://上游域名'],
            ['key' => 'user_id', 'label' => '用户ID（Userid）', 'type' => 'text', 'required' => true],
            ['key' => 'api_key', 'label' => 'ApiKey', 'type' => 'secret', 'required' => true],
            ['key' => 'notify_url', 'label' => '回调地址', 'type' => 'text', 'required' => true, 'placeholder' => 'https://我方域名/notify/supplier/供应商编码'],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        $business = [
            'external_orderno' => $request->attemptNo,
            'product_id' => $request->externalCode,
            'recharge_account' => $request->mobile,
            'notify_url' => $request->config['notify_url'] ?? '',
        ];
        if (in_array($request->faceValue, self::CHECKED_FACE_VALUES, true)) {
            $business['face_value'] = (string) $request->faceValue;
        }
        [$sent, $response] = $this->call($request->config, '/api/Order/create', $business, 10.0);
        $body = $this->decode($response['body']);
        if ($body === null) {
            // HTTP 5xx 页面、返回乱码：不知道建没建单，只能等查单确认
            return RechargeResult::processing($request->supplierOrderNo, '下单返回无法解析，等待查单：' . $this->describe($body, $response), $sent, $response['body']);
        }
        if ($body['status'] !== self::STATUS_OK) {
            return RechargeResult::failed('上游拒单：' . $this->describe($body, $response), $request->supplierOrderNo, $sent, $response['body']);
        }

        return RechargeResult::processing($request->supplierOrderNo, null, $sent, $response['body']);
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        return $this->queryOrder($request->config, $request->attemptNo, $request->supplierOrderNo);
    }

    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        $raw = (string) $request->getBody();
        $params = json_decode($raw, true);
        if (! is_array($params)) {
            $params = $request->getParsedBody();
        }
        $attemptNo = is_array($params) && is_scalar($params['external_orderno'] ?? null) ? (string) $params['external_orderno'] : '';
        if ($attemptNo === '') {
            return new CallbackResult(false, 'fail');
        }
        try {
            $result = $this->queryOrder($config, $attemptNo, null);
        } catch (Throwable) {
            // 查单失败就不应答 ok，让上游重推；定时查单也会继续
            return new CallbackResult(false, 'fail');
        }

        return new CallbackResult(true, 'ok', $attemptNo, $result->supplierOrderNo, $result);
    }

    public function balance(array $config): ?string
    {
        [, $response] = $this->call($config, '/api/User/balance', []);
        $body = $this->decode($response['body']);
        if ($body === null || $body['status'] !== self::STATUS_OK || ! is_scalar($body['data']['phone_balance'] ?? null)) {
            throw new RuntimeException('查询余额失败：' . mb_substr($response['body'], 0, 200));
        }

        return (string) $body['data']['phone_balance'];
    }

    /**
     * 按上游文档「签名生成」计算签名，返回实际发送的请求体和 Sign。
     *
     * @param array<string, string> $params
     * @return array{0: string, 1: string}
     */
    public static function sign(array $params, string $timestamp, string $apiKey): array
    {
        if ($params === []) {
            $json = '{}';
        } else {
            ksort($params, SORT_STRING);
            $json = (string) json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return [$json, sha1($timestamp . $json . $apiKey)];
    }

    /**
     * 查单并转成结果。只有上游返回了这笔单（单号对得上）且状态明确时才算成功 / 失败，其余都是处理中。
     *
     * @param array<string, string> $config
     */
    private function queryOrder(array $config, string $attemptNo, ?string $supplierOrderNo): RechargeResult
    {
        [$sent, $response] = $this->call($config, '/api/Order/query', ['external_orderno' => $attemptNo]);
        $body = $this->decode($response['body']);
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $matched = is_scalar($data['external_orderno'] ?? null) && (string) $data['external_orderno'] === $attemptNo;
        if ($body === null || $body['status'] !== self::STATUS_OK || ! $matched || ! is_numeric($data['status'] ?? null)) {
            return RechargeResult::processing($supplierOrderNo, '查单未拿到明确结果：' . $this->describe($body, $response), $sent, $response['body']);
        }
        $orderNo = is_scalar($data['orderno'] ?? null) && $data['orderno'] !== '' ? (string) $data['orderno'] : $supplierOrderNo;
        $reason = is_scalar($data['msg'] ?? null) ? trim((string) $data['msg']) : '';

        // 查单和回调的状态码含义不完全一样（3 在查单里是已失败、在回调里是取消），3 / 4 / 5 都是终态失败
        return match ((int) $data['status']) {
            2 => RechargeResult::success($orderNo, null, $sent, $response['body']),
            3 => RechargeResult::failed($reason === '' ? '上游已失败或取消' : $reason, $orderNo, $sent, $response['body']),
            4 => RechargeResult::failed('上游已退款', $orderNo, $sent, $response['body']),
            5 => RechargeResult::failed($reason === '' ? '上游充值失败' : $reason, $orderNo, $sent, $response['body']),
            default => RechargeResult::processing($orderNo, null, $sent, $response['body']),
        };
    }

    /**
     * 带签名请求头发 JSON 请求。
     *
     * @param array<string, string> $config
     * @param array<string, string> $params
     * @return array{0: string, 1: array{status: int, body: string}} 实际发送的请求体、响应
     */
    private function call(array $config, string $path, array $params, float $timeout = 5.0): array
    {
        $timestamp = (string) time();
        [$json, $sign] = self::sign($params, $timestamp, $config['api_key'] ?? '');
        $response = $this->http->postRaw(rtrim($config['api_url'] ?? '', '/') . $path, $json, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Sign' => $sign,
            'Timestamp' => $timestamp,
            'Userid' => $config['user_id'] ?? '',
        ], $timeout);

        return [$json, $response];
    }

    /**
     * @return null|array{status: int, msg?: mixed, data?: mixed}
     */
    private function decode(string $raw): ?array
    {
        $body = json_decode($raw, true);
        if (! is_array($body) || ! is_numeric($body['status'] ?? null)) {
            return null;
        }
        $body['status'] = (int) $body['status'];

        return $body;
    }

    /**
     * @param null|array{status: int, msg?: mixed} $body
     * @param array{status: int, body: string} $response
     */
    private function describe(?array $body, array $response): string
    {
        if ($body === null) {
            return 'HTTP ' . $response['status'] . ' ' . mb_substr($response['body'], 0, 100);
        }

        return '[' . $body['status'] . '] ' . (is_scalar($body['msg'] ?? null) ? (string) $body['msg'] : '');
    }
}
