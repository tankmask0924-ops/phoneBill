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

/**
 * 营业厅话费接口（订单派给线下营业厅员工人工充值），文档见 docs/营业厅.md（最后是对接方式和待确认事项）。
 *
 * - upstream_order_no 传提交单号 attempt_no，phone 传手机号，face_value 传面值，price 传这次提交的成本价（结算价，
 *   上游按它扣我们的预存款），notify_url 每单传；不传 province / city，按上游号段库识别；
 * - 鉴权在请求头 X-Api-Key / X-Timestamp / X-Sign；签名：业务参数加 timestamp，去掉空值，按名升序拼 k=v&...
 *   （不做 URL 编码），末尾拼 &key=密钥，md5 转大写；
 * - 下单 code=0 受理；文档列出的校验、鉴权、地区、额度类错误码没有建单，判失败换下一家；
 *   500、10000 和其他不确定的返回不知道建没建单，按接口异常处理，订单立即转异常人工处理；
 * - 查单只能按上游订单号查，下单时没拿到上游订单号的提交查不了（不能拿同一单号再下一次单来「查」，
 *   第一次没到上游的话就是真的下了一单）；
 * - 没有余额、商品列表接口。
 */
class YingyetingDriver implements SupplierDriverInterface
{
    private const PATH_ORDER = '/openapi/order';

    private const CODE_OK = '0';

    /** 下单时上游明确拒单（没有建单）的错误码 */
    private const REJECT_CODES = [
        '10001' => '参数错误',
        '10002' => '数据不存在',
        '50100' => '签名校验失败',
        '50101' => 'IP 不在白名单内',
        '50102' => '商户不存在或已禁用',
        '50103' => '请求时间戳无效',
        '60001' => '请求频率超限',
        '40103' => '手机号归属地识别失败',
        '40104' => '商品不可用或该地区未定价',
        '40105' => '该地区未开放放单',
        '40106' => '该地区订单池已满',
        '40107' => '该地区当日订单总量已达上限',
        '40108' => '该手机号存在处理中的订单',
        '40109' => '平台余额不足',
    ];

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'yingyeting';
    }

    public function name(): string
    {
        return '营业厅 API（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口地址', 'type' => 'text', 'required' => true, 'placeholder' => 'https://上游域名'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'text', 'required' => true],
            ['key' => 'secret', 'label' => '签名密钥', 'type' => 'secret', 'required' => true],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        if ($request->costPrice === null || $request->costPrice === '') {
            // 没发出去，一定没建单
            return RechargeResult::failed('供应商商品没有成本价，无法向上游传结算价');
        }
        [$sent, $response] = $this->call($request->config, 'POST', self::PATH_ORDER, array_filter([
            'upstream_order_no' => $request->attemptNo,
            'phone' => $request->mobile,
            'face_value' => (string) $request->faceValue,
            'price' => $request->costPrice,
            'notify_url' => $request->config['notify_url'] ?? '',
        ], static fn ($v) => $v !== ''), 10.0);
        $body = $this->decode($response['body']);
        if ($body !== null && isset(self::REJECT_CODES[$body['code']])) {
            return RechargeResult::failed('上游拒单：' . $this->describe($body, $response), $request->supplierOrderNo, $sent, $response['body']);
        }
        if ($body === null || $body['code'] !== self::CODE_OK) {
            // 500、10000、表外错误码、HTTP 出错、返回无法解析：不知道建没建单，转异常人工处理
            return RechargeResult::callFailed('上游返回不确定，转异常人工处理：' . $this->describe($body, $response), $sent, $response['body']);
        }
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $orderNo = $this->orderNo($data) ?? $request->supplierOrderNo;

        return is_numeric($data['status'] ?? null)
            ? $this->statusResult((int) $data['status'], $orderNo, $sent, $response['body'])
            : RechargeResult::processing($orderNo, null, $sent, $response['body']);
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        $orderNo = $request->supplierOrderNo;
        if ($orderNo === null || $orderNo === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $orderNo) !== 1) {
            return RechargeResult::processing($orderNo, '没有上游订单号（下单时没拿到返回），无法查单，请到上游核实');
        }
        [$sent, $response] = $this->call($request->config, 'GET', self::PATH_ORDER . '/' . $orderNo, []);
        $body = $this->decode($response['body']);
        $data = $body !== null && $body['code'] === self::CODE_OK && is_array($body['data'] ?? null) ? $body['data'] : null;
        if ($data === null || ! is_numeric($data['status'] ?? null) || ! $this->sameOrder($data, $request->attemptNo)) {
            // 包括 40100 订单不存在：单号是上游自己返回的，查不到不正常，不能判失败
            return RechargeResult::processing($orderNo, '查单未拿到明确结果：' . $this->describe($body, $response), $sent, $response['body']);
        }

        return $this->statusResult((int) $data['status'], $orderNo, $sent, $response['body']);
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
        $attemptNo = is_scalar($params['upstream_order_no'] ?? null) ? (string) $params['upstream_order_no'] : '';
        $given = is_string($params['sign'] ?? null) ? strtoupper($params['sign']) : '';
        $secret = $config['secret'] ?? '';
        if ($attemptNo === '' || $given === '' || $secret === '' || ! hash_equals(self::sign($params, $secret), $given) || ! is_numeric($params['status'] ?? null)) {
            return new CallbackResult(false, 'fail');
        }
        $orderNo = $this->orderNo($params);

        return new CallbackResult(true, 'success', $attemptNo, $orderNo, $this->statusResult((int) $params['status'], $orderNo, null, $raw));
    }

    public function balance(array $config): ?string
    {
        return null;
    }

    /**
     * 按上游文档第 4 章计算签名，请求签名和回调验签共用：去掉 sign 和值为 null、空串、数组的字段，
     * 按名升序拼 k=v&...（不做 URL 编码），末尾拼 &key=密钥，md5 转大写。
     *
     * @param array<string, mixed> $params
     */
    public static function sign(array $params, string $secret): string
    {
        unset($params['sign']);
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }
            $pairs[] = $key . '=' . (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }

        return strtoupper(md5(implode('&', $pairs) . '&key=' . $secret));
    }

    /**
     * 回调里的 face_value、price、status 等是 JSON 数字，上游按报文里的原样签名（48.5 而不是 48.50），
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
     * 订单状态：3 已完成成功；5 已拒绝、6 已退单失败；7 已冲正是成功后被撤销，按失败报给上层
     * （订单已成功时不会自动改，记错误日志等人工冲正）；0 待接单、1 充值中和历史状态 2、4 都是处理中。
     */
    private function statusResult(int $status, ?string $orderNo, ?string $request, string $raw): RechargeResult
    {
        return match ($status) {
            3 => RechargeResult::success($orderNo, null, $request, $raw),
            5 => RechargeResult::failed('上游已拒绝', $orderNo, $request, $raw),
            6 => RechargeResult::failed('上游已退单', $orderNo, $request, $raw),
            7 => RechargeResult::failed('上游已冲正（成功后被撤销）', $orderNo, $request, $raw),
            default => RechargeResult::processing($orderNo, null, $request, $raw),
        };
    }

    /**
     * 上游带回了上游订单号（我们的提交单号）时必须是这一笔。
     *
     * @param array<string, mixed> $data
     */
    private function sameOrder(array $data, string $attemptNo): bool
    {
        return ! is_scalar($data['upstream_order_no'] ?? null) || (string) $data['upstream_order_no'] === '' || (string) $data['upstream_order_no'] === $attemptNo;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function orderNo(array $data): ?string
    {
        return is_scalar($data['order_no'] ?? null) && (string) $data['order_no'] !== '' ? (string) $data['order_no'] : null;
    }

    /**
     * 带上鉴权请求头发请求：POST 用表单（文档推荐，签什么传什么），GET 没有参数，只签 timestamp。
     *
     * @param array<string, string> $config
     * @param array<string, string> $params
     * @return array{0: string, 1: array{status: int, body: string}} 实际发送的参数（不含签名）、响应
     */
    private function call(array $config, string $method, string $path, array $params, float $timeout = 5.0): array
    {
        $timestamp = (string) time();
        $headers = [
            'X-Api-Key' => $config['api_key'] ?? '',
            'X-Timestamp' => $timestamp,
            'X-Sign' => self::sign($params + ['timestamp' => $timestamp], $config['secret'] ?? ''),
        ];
        $body = '';
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $body = http_build_query($params);
        }
        $url = rtrim($config['api_url'] ?? '', '/') . $path;
        $response = $this->http->send($method, $url, $headers, $body, $timeout);

        return [(string) json_encode(['method' => $method, 'path' => $path] + $params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $response];
    }

    /**
     * code 统一转成字符串（文档里是数字）。
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
}
