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
 * 皮口袋话费接口，文档见 docs/话费接口.md（产品编码下单、查单、余额、回调，最后是对接方式和待确认事项）。
 *
 * - merchant_order_no 传提交单号 attempt_no，product_no 传供应商商品编码，equity_number 传手机号，num 固定 1；
 * - 全部接口 POST JSON；签名：除 sign 外参数按名 ASCII 升序拼 键值键值...（无分隔符，空值也参与），末尾拼密钥，md5 小写；
 * - 下单 code=1000 受理、1003 订单号重复（之前提交过）都是处理中；验签、IP、额度、参数、商户号、产品等校验类错误码
 *   是上游明确拒单，判失败换下一家；1007 系统繁忙和文档外的错误码不知道建没建单，处理中等查单；
 * - 响应里的 sign 怎么算（data 是嵌套对象）文档没说，不验。
 */
class PikoudaiDriver implements SupplierDriverInterface
{
    private const PATH_RECHARGE = '/api/order/phone-by-product';

    private const PATH_QUERY = '/api/order/getorder';

    private const PATH_BALANCE = '/api/merchant/balance';

    private const CODE_OK = '1000';

    /** 订单号重复：之前已经提交过，绝不能当失败 */
    private const CODE_DUPLICATE = '1003';

    /** 下单时上游明确拒单（校验没过，没有建单）的错误码 */
    private const REJECT_CODES = [
        '1001' => '验签失败',
        '1002' => 'IP不正确',
        '1004' => '额度不足',
        '1005' => '价格配置不正确',
        '1006' => '匹配参数值不能为空',
        '1008' => '商户号不存在',
        '1011' => '禁止进单',
        '1012' => '产品Key不正确',
    ];

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'pikoudai';
    }

    public function name(): string
    {
        return '皮口袋 API（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口域名', 'type' => 'text', 'required' => true, 'placeholder' => 'https://上游域名'],
            ['key' => 'merchant_no', 'label' => '商户编号（merchant_no）', 'type' => 'text', 'required' => true],
            ['key' => 'secret', 'label' => '密钥', 'type' => 'secret', 'required' => true],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, self::PATH_RECHARGE, [
            'merchant_order_no' => $request->attemptNo,
            'product_no' => $request->externalCode,
            'num' => 1,
            'equity_number' => $request->mobile,
            'notify_url' => $request->config['notify_url'] ?? '',
        ], 10.0);
        $body = $this->decode($response['body']);
        if ($body !== null && isset(self::REJECT_CODES[$body['code']])) {
            return RechargeResult::failed('上游拒单：' . $this->describe($body, $response), $request->supplierOrderNo, $sent, $response['body']);
        }
        if ($body !== null && $body['code'] === self::CODE_DUPLICATE) {
            return RechargeResult::processing($request->supplierOrderNo, '上游订单号已存在（之前已提交过），等待结果', $sent, $response['body']);
        }
        if ($body === null || $body['code'] !== self::CODE_OK) {
            // 包括 1007 系统繁忙：不知道建没建单，判失败可能导致退款后上游又充上，只能等查单确认
            return RechargeResult::processing($request->supplierOrderNo, '下单未确认受理，等待查单：' . $this->describe($body, $response), $sent, $response['body']);
        }

        return RechargeResult::processing($this->orderNo($body['data'] ?? null) ?? $request->supplierOrderNo, null, $sent, $response['body']);
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, self::PATH_QUERY, ['merchant_order_no' => $request->attemptNo]);
        $body = $this->decode($response['body']);
        $data = $body !== null && $body['code'] === self::CODE_OK && is_array($body['data'] ?? null) ? $body['data'] : null;
        if ($data === null || ! is_string($data['orderStatus'] ?? null) || ! $this->sameOrder($data, $request->attemptNo)) {
            return RechargeResult::processing($request->supplierOrderNo, '查单未拿到明确结果：' . $this->describe($body, $response), $sent, $response['body']);
        }

        return $this->statusResult($data['orderStatus'], $this->orderNo($data) ?? $request->supplierOrderNo, $sent, $response['body']);
    }

    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        $raw = (string) $request->getBody();
        $params = self::decodeKeepingNumbers($raw);
        if ($params === null) {
            $params = $request->getParsedBody();
        }
        if (! is_array($params)) {
            return new CallbackResult(false, 'fail');
        }
        $params = array_map(static fn ($v) => self::stringify($v), $params);
        $attemptNo = $params['MerchantOrderNo'] ?? '';
        $given = strtolower($params['Sign'] ?? '');
        $secret = $config['secret'] ?? '';
        unset($params['Sign']);
        if ($attemptNo === '' || $given === '' || $secret === '' || ! hash_equals(self::sign($params, $secret), $given)) {
            return new CallbackResult(false, 'fail');
        }
        if (($params['MerchantNo'] ?? '') !== ($config['merchant_no'] ?? '')) {
            return new CallbackResult(false, 'fail');
        }

        return new CallbackResult(true, 'success', $attemptNo, null, $this->statusResult($params['Status'] ?? '', null, null, $raw));
    }

    public function balance(array $config): ?string
    {
        [, $response] = $this->call($config, self::PATH_BALANCE, ['timestamp' => (string) (int) (microtime(true) * 1000)]);
        $body = $this->decode($response['body']);
        if ($body === null || $body['code'] !== self::CODE_OK || ! is_scalar($body['data']['balance'] ?? null)) {
            throw new RuntimeException('查询余额失败：' . mb_substr($response['body'], 0, 200));
        }

        return (string) $body['data']['balance'];
    }

    /**
     * 按上游文档「签名算法」计算签名，请求签名和回调验签共用：去掉 sign，按名 ASCII 升序，
     * 键和值直接首尾相连（空值也参与），末尾拼密钥，md5 小写。
     *
     * @param array<string, mixed> $params
     */
    public static function sign(array $params, string $secret): string
    {
        unset($params['sign']);
        ksort($params, SORT_STRING);
        $plain = '';
        foreach ($params as $key => $value) {
            $plain .= $key . self::stringify($value);
        }

        return md5($plain . $secret);
    }

    /**
     * 回调里的 Payment 可能是 JSON 数字，json_decode 会把 48.50 变成 48.5 导致验签对不上，
     * 先把顶层的数字值原样转成字符串再解析。
     *
     * @return null|array<string, mixed>
     */
    private static function decodeKeepingNumbers(string $raw): ?array
    {
        $quoted = preg_replace('/("[A-Za-z0-9_]+"\s*:\s*)(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(\s*[,}])/', '$1"$2"$3', $raw);
        $params = json_decode($quoted ?? $raw, true);

        return is_array($params) ? $params : null;
    }

    /**
     * 查单和回调的状态：Success 成功、Failure 失败，其他（Processing 等）都是处理中。
     */
    private function statusResult(string $status, ?string $supplierOrderNo, ?string $request, string $raw): RechargeResult
    {
        return match ($status) {
            'Success' => RechargeResult::success($supplierOrderNo, null, $request, $raw),
            'Failure' => RechargeResult::failed('上游充值失败', $supplierOrderNo, $request, $raw),
            default => RechargeResult::processing($supplierOrderNo, null, $request, $raw),
        };
    }

    /**
     * 上游带回了商户订单号时必须是这一笔。
     *
     * @param array<string, mixed> $data
     */
    private function sameOrder(array $data, string $attemptNo): bool
    {
        return ! is_scalar($data['merchantOrderNo'] ?? null) || (string) $data['merchantOrderNo'] === '' || (string) $data['merchantOrderNo'] === $attemptNo;
    }

    private function orderNo(mixed $data): ?string
    {
        return is_array($data) && is_scalar($data['orderNo'] ?? null) && (string) $data['orderNo'] !== '' ? (string) $data['orderNo'] : null;
    }

    /**
     * 带上 merchant_no 和签名发 JSON 请求。
     *
     * @param array<string, string> $config
     * @param array<string, int|string> $business
     * @return array{0: string, 1: array{status: int, body: string}} 实际发送的参数（不含 sign）、响应
     */
    private function call(array $config, string $path, array $business, float $timeout = 5.0): array
    {
        $params = $business + ['merchant_no' => $config['merchant_no'] ?? ''];
        $url = rtrim($config['api_url'] ?? '', '/') . $path;
        $response = $this->http->postJson($url, $params + ['sign' => self::sign($params, $config['secret'] ?? '')], $timeout);

        return [(string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $response];
    }

    /**
     * code 统一转成字符串（文档示例里是数字）。
     *
     * @return null|array<string, mixed>
     */
    private function decode(string $raw): ?array
    {
        $body = json_decode($raw, true);
        if (! is_array($body) || ! is_scalar($body['code'] ?? null)) {
            return null;
        }
        $body['code'] = (string) $body['code'];

        return $body;
    }

    /**
     * @param null|array<string, mixed> $body
     * @param array{status: int, body: string} $response
     */
    private function describe(?array $body, array $response): string
    {
        if ($body === null) {
            return 'HTTP ' . $response['status'] . ' ' . mb_substr($response['body'], 0, 100);
        }

        $message = is_scalar($body['message'] ?? null) ? trim((string) $body['message']) : '';

        return '[' . $body['code'] . '] ' . ($message === '' ? self::REJECT_CODES[$body['code']] ?? '' : $message);
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return $value === null ? '' : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
