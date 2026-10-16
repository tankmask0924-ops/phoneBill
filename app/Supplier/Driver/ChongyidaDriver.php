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
use App\Supplier\UpstreamProduct;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * 充易达开放接口，文档见 docs/充易达.md（最后是对接方式和结果判定表）。只接账户信息、商品列表、下单、查单。
 *
 * - clientOrderNo 传提交单号 attempt_no，productId 传供应商商品编码，phone 传手机号；
 * - 全部接口 POST JSON，公共参数 userNo + 毫秒 timestamp；签名：去掉 sign 和空值，按名 ASCII 升序拼 k=v&...
 *   （不做 URL 编码），末尾拼 &secretKey=，md5 转大写；
 * - 下单只有 code=0 算受理；上游有返回、code 是别的（含 3001 订单号重复）一律判失败换下一家；
 *   网络异常、HTTP 出错、返回无法解析（没有拿到返回值）按接口异常处理，订单立即转异常人工处理；
 * - 查单 2003 未查询到数据只记录，订单状态不动（下单受理了就以下单为准）；
 * - 暂不接结果回调（notifyUrl 是必填，照常传自动生成的回调地址），结果靠查单；下单响应里的 sign 文档没说怎么算，不验。
 */
class ChongyidaDriver implements SupplierDriverInterface, ProvidesUpstreamProducts
{
    private const PATH_ACCOUNT = '/open/v1/accounts';

    private const PATH_CATALOG = '/open/v1/catalog';

    private const PATH_CHECKOUT = '/open/v1/checkout';

    private const PATH_ORDERS = '/open/v1/orders';

    private const CODE_OK = '0';

    /** 查单时查不到订单：只记录，订单状态不动 */
    private const CODE_NOT_FOUND = '2003';

    /** 错误码的文档说明：上游没给描述时用；下单除了 0 都按失败处理 */
    private const ERROR_CODES = [
        '1001' => '用户不存在',
        '1002' => '签名不正确',
        '1003' => '请求已过期',
        '1004' => '缺少必要的签名参数',
        '1005' => '用户已被禁用',
        '1006' => 'IP 不在白名单内',
        '2001' => '参数错误',
        '2002' => '缺少必填参数',
        '2003' => '未查询到数据',
        '3001' => '订单号重复',
        '9000' => '服务错误',
    ];

    /** 上游 carrier：1 移动、2 联通、3 电信 */
    private const OPERATORS = ['1' => Operator::Cmcc, '2' => Operator::Cucc, '3' => Operator::Ctcc];

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'chongyida';
    }

    public function name(): string
    {
        return '充易达 API（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口域名', 'type' => 'text', 'required' => true, 'placeholder' => 'https://上游域名'],
            ['key' => 'user_no', 'label' => '用户编号（userNo）', 'type' => 'text', 'required' => true],
            ['key' => 'secret_key', 'label' => '密钥（secretKey）', 'type' => 'secret', 'required' => true],
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
            // HTTP 出错、返回无法解析：没有拿到返回值，不知道建没建单，转异常人工处理
            return RechargeResult::callFailed('下单没有拿到上游返回，转异常人工处理：' . $this->describe($body, $response), $sent, $response['body']);
        }
        if ($body['code'] !== self::CODE_OK) {
            return RechargeResult::failed('上游拒单：' . $this->describe($body, $response), $request->supplierOrderNo, $sent, $response['body']);
        }

        return RechargeResult::processing($this->orderNo($body['data'] ?? null) ?? $request->supplierOrderNo, null, $sent, $response['body']);
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, self::PATH_ORDERS, ['clientOrderNo' => $request->attemptNo]);
        $body = $this->decode($response['body']);
        if ($body !== null && $body['code'] === self::CODE_NOT_FOUND) {
            return RechargeResult::processing($request->supplierOrderNo, '查单：上游查不到这笔订单，只记录：' . $this->describe($body, $response), $sent, $response['body']);
        }
        $order = $body !== null && $body['code'] === self::CODE_OK ? $this->findOrder($body['data'] ?? null, $request->attemptNo) : null;
        if ($order === null || ! is_string($order['orderStatus'] ?? null)) {
            return RechargeResult::processing($request->supplierOrderNo, '查单未拿到明确结果：' . $this->describe($body, $response), $sent, $response['body']);
        }
        $orderNo = $this->orderNo($order) ?? $request->supplierOrderNo;

        return match ($order['orderStatus']) {
            'SUCCESS' => RechargeResult::success($orderNo, null, $sent, $response['body']),
            'FAILED' => RechargeResult::failed('上游充值失败', $orderNo, $sent, $response['body']),
            default => RechargeResult::processing($orderNo, null, $sent, $response['body']),
        };
    }

    /**
     * 暂不接回调，结果以查单为准。
     */
    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        return new CallbackResult(false, 'fail');
    }

    public function balance(array $config): ?string
    {
        [, $response] = $this->call($config, self::PATH_ACCOUNT, []);
        $body = $this->decode($response['body']);
        if ($body === null || $body['code'] !== self::CODE_OK || ! is_scalar($body['data']['balance'] ?? null)) {
            throw new RuntimeException('查询余额失败：' . mb_substr($response['body'], 0, 200));
        }

        return (string) $body['data']['balance'];
    }

    /**
     * 商品列表一次全部返回（不传 faceValue 筛选）。
     */
    public function upstreamProducts(array $config): array
    {
        [, $response] = $this->call($config, self::PATH_CATALOG, []);
        $body = $this->decode($response['body']);
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
     * 按上游文档「签名规则说明」计算签名：去掉 sign 和空值，按名 ASCII 升序拼 k=v&...（不做 URL 编码），
     * 末尾拼 &secretKey=，md5 转大写。
     *
     * @param array<string, mixed> $params
     */
    public static function sign(array $params, string $secretKey): string
    {
        unset($params['sign']);
        $params = self::withoutEmpty($params);
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        $pairs[] = 'secretKey=' . $secretKey;

        return strtoupper(md5(implode('&', $pairs)));
    }

    /**
     * 文档要求空值（null、''）不传、也不参与签名。
     *
     * @param array<string, mixed> $params
     * @return array<string, int|string>
     */
    private static function withoutEmpty(array $params): array
    {
        return array_filter($params, static fn ($v) => (is_string($v) && $v !== '') || is_int($v));
    }

    /**
     * 查单 data 是订单列表，按接入方单号找出这一笔。
     *
     * @return null|array<string, mixed>
     */
    private function findOrder(mixed $data, string $attemptNo): ?array
    {
        if (! is_array($data)) {
            return null;
        }
        foreach (array_is_list($data) ? $data : [$data] as $item) {
            if (is_array($item) && is_scalar($item['clientOrderNo'] ?? null) && (string) $item['clientOrderNo'] === $attemptNo) {
                return $item;
            }
        }

        return null;
    }

    private function orderNo(mixed $data): ?string
    {
        return is_array($data) && is_scalar($data['orderNo'] ?? null) && (string) $data['orderNo'] !== '' ? (string) $data['orderNo'] : null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function toUpstreamProduct(array $item): UpstreamProduct
    {
        $operators = [];
        foreach (is_array($item['carrier'] ?? null) ? $item['carrier'] : [] as $carrier) {
            if (is_scalar($carrier) && isset(self::OPERATORS[(string) $carrier])) {
                $operators[] = self::OPERATORS[(string) $carrier]->value;
            }
        }
        $note = array_filter([
            is_scalar($item['category'] ?? null) ? trim((string) $item['category']) : '',
            is_scalar($item['type'] ?? null) ? trim((string) $item['type']) : '',
        ], static fn ($v) => $v !== '');

        return new UpstreamProduct(
            (string) $item['productId'],
            is_scalar($item['name'] ?? null) ? (string) $item['name'] : '',
            is_scalar($item['price'] ?? null) && $item['price'] !== '' ? (string) $item['price'] : null,
            is_numeric($item['faceValue'] ?? null) ? (int) $item['faceValue'] : null,
            array_values(array_intersect(Operator::values(), $operators)),
            true,
            $note === [] ? null : implode(' · ', $note),
        );
    }

    /**
     * 带上 userNo、毫秒时间戳和签名发 JSON 请求，空值不传。
     *
     * @param array<string, string> $config
     * @param array<string, string> $business
     * @return array{0: string, 1: array{status: int, body: string}} 实际发送的参数（不含 sign）、响应
     */
    private function call(array $config, string $path, array $business, float $timeout = 5.0): array
    {
        $params = self::withoutEmpty($business + [
            'userNo' => $config['user_no'] ?? '',
            'timestamp' => (int) (microtime(true) * 1000),
        ]);
        $url = rtrim($config['api_url'] ?? '', '/') . $path;
        $response = $this->http->postJson($url, $params + ['sign' => self::sign($params, $config['secret_key'] ?? '')], $timeout);

        return [(string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $response];
    }

    /**
     * code 统一转成字符串。
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

        return '[' . $body['code'] . '] ' . ($message === '' ? self::ERROR_CODES[$body['code']] ?? '' : $message);
    }
}
