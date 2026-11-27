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

use App\Enum\Operator;
use App\Network\HttpClient;
use App\Supplier\CallbackResult;
use App\Supplier\ProvidesUpstreamProducts;
use App\Supplier\RechargeRequest;
use App\Supplier\RechargeResult;
use App\Supplier\SupplierDriverInterface;
use App\Supplier\UnclearResultException;
use App\Supplier\UpstreamProduct;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * 记得省开放接口，文档见 docs/记得省.md（最后是对接方式、结果判定表和待确认事项）。只接话费：下单、查单、余额、商品列表、回调。
 *
 * - clientOrderNo 传提交单号 attempt_no，phone 传手机号，productId 传供应商商品编码，notifyUrl 传自动生成的回调地址；
 * - 全部接口 POST JSON，公共参数 userNo + 13 位毫秒 timestamp；签名：去掉 sign、null 和空串，按名升序拼 k=v&...，
 *   末尾拼 &secretKey=，md5 转大写；回调用同一算法验签；
 * - 下单 code=0 算受理；3001 重复提交说明上游已有这笔单，按处理中；9000 服务错误不知道建没建单，转异常人工处理；
 *   其他 code 判失败换下一家；网络异常、返回无法解析或没有 code（没拿到返回值）转异常人工处理；
 * - 查单 / 回调 orderStatus SUCCESS 成功、FAILED 失败（含撤单退款、返销），CREATED / PROCESSING / PENDING_SUPPLEMENT 和其他处理中；
 * - 查单 2003 查不到这笔订单只记录（标记查无此单，异常订单人工查单时据此置失败）；其他报错按没拿到明确结果处理。
 */
class JideshengDriver implements SupplierDriverInterface, ProvidesUpstreamProducts
{
    private const PATH_CHECKOUT = '/open/v1/checkout';

    private const PATH_ORDERS = '/open/v1/orders';

    private const PATH_ACCOUNTS = '/open/v1/accounts';

    private const PATH_CATALOG = '/open/v1/catalog';

    private const CODE_OK = '0';

    /** 未查询到数据，或订单不属于本账户 */
    private const CODE_NOT_FOUND = '2003';

    /** 重复提交：clientOrderNo 上游已经有了，不能判失败 */
    private const CODE_DUPLICATE = '3001';

    /** 服务错误：不知道建没建单 */
    private const CODE_SERVER_ERROR = '9000';

    private const ORDER_SUCCESS = 'SUCCESS';

    /** 充值失败、撤单退款、返销 */
    private const ORDER_FAILED = 'FAILED';

    /** 商品列表 carrier：1 移动，2 联通，3 电信 */
    private const CARRIERS = ['1' => Operator::Cmcc, '2' => Operator::Cucc, '3' => Operator::Ctcc];

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'jidesheng';
    }

    public function name(): string
    {
        return '记得省（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口域名', 'type' => 'text', 'required' => true, 'placeholder' => 'https://上游域名（不含 /open/v1）'],
            ['key' => 'user_no', 'label' => '代理编号 userNo', 'type' => 'text', 'required' => true],
            ['key' => 'secret_key', 'label' => '商户密钥 secretKey', 'type' => 'secret', 'required' => true],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, self::PATH_CHECKOUT, [
            'productId' => $request->externalCode,
            'phone' => $request->mobile,
            'clientOrderNo' => $request->attemptNo,
            'notifyUrl' => $request->config['notify_url'] ?? '',
        ], 10.0);
        $body = $this->decode($response['body']);
        if ($body === null) {
            // 返回无法解析、没有 code：没有拿到返回值，不知道建没建单，转异常人工处理
            return RechargeResult::callFailed('下单没有拿到上游返回，转异常人工处理：' . $this->describe($body, $response), $sent, $response['body']);
        }
        if ($body['code'] === self::CODE_DUPLICATE) {
            return RechargeResult::processing($request->supplierOrderNo, '上游提示重复提交（已有这笔订单），按处理中：' . $this->describe($body, $response), $sent, $response['body']);
        }
        if ($body['code'] === self::CODE_SERVER_ERROR) {
            return RechargeResult::callFailed('上游服务错误，不知道是否已建单，转异常人工处理：' . $this->describe($body, $response), $sent, $response['body']);
        }
        if ($body['code'] !== self::CODE_OK) {
            return RechargeResult::failed('上游拒单：' . $this->describe($body, $response), $request->supplierOrderNo, $sent, $response['body']);
        }
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        // 受理成功并已冻结金额，结果以回调或查单为准
        return RechargeResult::processing($this->orderNo($data) ?? $request->supplierOrderNo, null, $sent, $response['body']);
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, self::PATH_ORDERS, ['clientOrderNo' => $request->attemptNo]);
        $body = $this->decode($response['body']);
        if ($body !== null && $body['code'] === self::CODE_NOT_FOUND) {
            // 下单受理了就以下单为准，查不到只记录；异常订单人工查单时按查无此单置失败
            return RechargeResult::processing($request->supplierOrderNo, '查单：上游查不到这笔订单，只记录：' . $this->describe($body, $response), $sent, $response['body'])->asNotFound();
        }
        $order = null;
        if ($body !== null && $body['code'] === self::CODE_OK && is_array($body['data'] ?? null)) {
            foreach ($body['data'] as $item) {
                if (is_array($item) && is_scalar($item['clientOrderNo'] ?? null) && (string) $item['clientOrderNo'] === $request->attemptNo) {
                    $order = $item;
                    break;
                }
            }
        }
        if ($order === null || ! is_scalar($order['orderStatus'] ?? null)) {
            return RechargeResult::processing($request->supplierOrderNo, '查单未拿到明确结果：' . $this->describe($body, $response), $sent, $response['body']);
        }

        return $this->conclude($order, $this->orderNo($order) ?? $request->supplierOrderNo, $sent, $response['body']);
    }

    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        $raw = (string) $request->getBody();
        $params = json_decode($raw, true);
        if (! is_array($params)) {
            $params = $request->getParsedBody();
        }
        if (! is_array($params)) {
            return new CallbackResult(false, 'fail');
        }
        $given = is_string($params['sign'] ?? null) ? strtoupper($params['sign']) : '';
        $secret = $config['secret_key'] ?? '';
        if ($given === '' || $secret === '' || ! hash_equals(self::sign($params, $secret), $given)) {
            return new CallbackResult(false, 'fail');
        }
        $attemptNo = is_scalar($params['clientOrderNo'] ?? null) ? (string) $params['clientOrderNo'] : '';
        $orderNo = $this->orderNo($params);
        if ($attemptNo === '' && $orderNo === null) {
            return new CallbackResult(false, 'fail');
        }

        return new CallbackResult(true, 'success', $attemptNo === '' ? null : $attemptNo, $orderNo, $this->conclude($params, $orderNo, null, $raw));
    }

    public function balance(array $config): ?string
    {
        [, $response] = $this->call($config, self::PATH_ACCOUNTS, []);
        $body = $this->decode($response['body']);
        if ($body === null || $body['code'] !== self::CODE_OK || ! is_numeric($body['data']['balance'] ?? null)) {
            throw new RuntimeException('查询余额失败：' . $this->describe($body, $response));
        }

        return (string) $body['data']['balance'];
    }

    public function upstreamProducts(array $config): array
    {
        [, $response] = $this->call($config, self::PATH_CATALOG, []);
        $body = $this->decode($response['body']);
        if ($body !== null && $body['code'] === self::CODE_NOT_FOUND) {
            // 无匹配产品
            return [];
        }
        if ($body === null || $body['code'] !== self::CODE_OK || ! is_array($body['data'] ?? null)) {
            throw new RuntimeException('查询商品失败：' . $this->describe($body, $response));
        }

        $products = [];
        foreach ($body['data'] as $item) {
            if (is_array($item) && is_scalar($item['productId'] ?? null) && (string) $item['productId'] !== '') {
                $products[] = $this->toUpstreamProduct($item);
            }
        }

        return $products;
    }

    /**
     * 按文档「签名」计算：去掉 sign、null 和空串（数字 0 参与），按名 ASCII 升序拼 k=v&...，末尾拼 &secretKey=，md5 转大写。
     * 多个值（数组）用英文逗号拼成一个字符串；数字按 JSON 里的写法（整数不带小数点）。
     *
     * @param array<string, mixed> $params
     */
    public static function sign(array $params, string $secretKey): string
    {
        unset($params['sign']);
        $pairs = [];
        foreach ($params as $key => $value) {
            $text = self::signValue($value);
            if ($text !== null) {
                $pairs[(string) $key] = $text;
            }
        }
        ksort($pairs, SORT_STRING);
        $parts = [];
        foreach ($pairs as $key => $text) {
            $parts[] = $key . '=' . $text;
        }
        $parts[] = 'secretKey=' . $secretKey;

        return strtoupper(md5(implode('&', $parts)));
    }

    /**
     * 参与签名的值；null、空串返回 null（不参与）。
     */
    private static function signValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode(',', array_map(static fn ($v) => self::signValue($v) ?? '', $value));
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_float($value)) {
            $value = floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : (string) json_encode($value);
        } elseif ($value !== null && ! is_scalar($value)) {
            return null;
        }
        $text = (string) $value;

        return $value === null || $text === '' ? null : $text;
    }

    /**
     * 带上 userNo、毫秒时间戳和签名发 JSON POST；空值不发。
     *
     * @param array<string, string> $config
     * @param array<string, string> $business
     * @return array{0: string, 1: array{status: int, body: string}} 实际发送的参数（不含 sign）、响应
     * @throws UnclearResultException 连不上、超时等，结果里带着发出的请求报文
     */
    private function call(array $config, string $path, array $business, float $timeout = 5.0): array
    {
        $params = array_filter(['userNo' => $config['user_no'] ?? ''] + $business, static fn (string $v) => $v !== '');
        $params['timestamp'] = (int) floor(microtime(true) * 1000);
        $url = rtrim($config['api_url'] ?? '', '/') . $path;
        $sent = (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $response = $this->http->postJson($url, $params + ['sign' => self::sign($params, $config['secret_key'] ?? '')], $timeout);
        } catch (Throwable $e) {
            throw new UnclearResultException(RechargeResult::callFailed(mb_substr('调用异常：' . $e->getMessage(), 0, 255), $sent), $e);
        }

        return [$sent, $response];
    }

    /**
     * 没有 code 算没拿到返回值；HTTP 4xx / 5xx 只要带着可解析的 code 也按上游的回复处理。code 统一成字符串。
     *
     * @return null|array<string, mixed>
     */
    private function decode(string $raw): ?array
    {
        $body = json_decode($raw, true);
        if (! is_array($body) || ! is_scalar($body['code'] ?? null) || (string) $body['code'] === '') {
            return null;
        }
        $body['code'] = (string) $body['code'];

        return $body;
    }

    /**
     * 按 orderStatus 判定：SUCCESS 成功，FAILED 失败（含撤单退款、返销），其他处理中。
     *
     * @param array<string, mixed> $order
     */
    private function conclude(array $order, ?string $orderNo, ?string $sent, string $raw): RechargeResult
    {
        $status = is_scalar($order['orderStatus'] ?? null) ? (string) $order['orderStatus'] : '';
        if ($status === self::ORDER_SUCCESS) {
            return RechargeResult::success($orderNo, null, $sent, $raw);
        }
        if ($status === self::ORDER_FAILED) {
            $reason = is_scalar($order['failReason'] ?? null) ? trim((string) $order['failReason']) : '';

            return RechargeResult::failed('上游充值失败' . ($reason === '' ? '' : "：{$reason}"), $orderNo, $sent, $raw);
        }

        // CREATED 未充值、PROCESSING 充值中、PENDING_SUPPLEMENT 压单和表外的状态
        return RechargeResult::processing($orderNo, null, $sent, $raw);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function orderNo(array $data): ?string
    {
        return is_scalar($data['orderNo'] ?? null) && (string) $data['orderNo'] !== '' ? (string) $data['orderNo'] : null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function toUpstreamProduct(array $item): UpstreamProduct
    {
        $operators = [];
        foreach (is_array($item['carrier'] ?? null) ? $item['carrier'] : [] as $carrier) {
            $operator = is_scalar($carrier) ? (self::CARRIERS[(string) $carrier] ?? null) : null;
            if ($operator !== null && ! in_array($operator->value, $operators, true)) {
                $operators[] = $operator->value;
            }
        }
        $notes = [];
        foreach (['category', 'type'] as $key) {
            if (is_scalar($item[$key] ?? null) && trim((string) $item[$key]) !== '') {
                $notes[] = trim((string) $item[$key]);
            }
        }
        $faceValue = $item['faceValue'] ?? null;

        return new UpstreamProduct(
            (string) $item['productId'],
            is_scalar($item['name'] ?? null) ? (string) $item['name'] : '',
            is_numeric($item['price'] ?? null) ? (string) $item['price'] : null,
            is_numeric($faceValue) && floor((float) $faceValue) === (float) $faceValue ? (int) $faceValue : null,
            $operators,
            true,
            $notes === [] ? null : implode(' / ', $notes),
        );
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

        return '[' . $body['code'] . '] ' . (is_scalar($body['message'] ?? null) ? trim((string) $body['message']) : '');
    }
}
