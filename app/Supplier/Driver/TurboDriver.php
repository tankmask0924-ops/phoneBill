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
use Throwable;

/**
 * Turbo TOPUP 接口，文档见 docs/Turbo-TOPUP-API.md（最后是对接方式和结果判定表）。只接话费：下单、查单、余额、商品列表、回调。
 *
 * - externalOrderNo 传提交单号 attempt_no，phone 传手机号，productId 传供应商商品编码，notificationUrl 传自动生成的回调地址；
 * - 全部接口 POST JSON，空值不发；签名：去掉 sign，按名升序拼 k=v&...（值不编码），末尾拼 &secretKey=，md5 转大写；
 * - 下单 status=success 算受理；上游有返回、status 是别的一律判失败换下一家；
 *   网络异常、返回无法解析或没有 status（没有拿到返回值）按接口异常处理，订单立即转异常人工处理；
 * - 下单 / 查单的 orderStatus Succeeded 成功、Failed / Cancel 失败、其他处理中；rechargeStatus=3 部分成功不判结果，等人工核实；
 * - 回调不带签名，不能信：只取订单号，结果以主动查单为准；查单没拿到明确结果时回 fail。
 */
class TurboDriver implements SupplierDriverInterface, ProvidesUpstreamProducts
{
    private const PATH_CHECKOUT = '/integration/v1/checkout';

    private const PATH_ORDERS = '/integration/v1/orders';

    private const PATH_ACCOUNTS = '/integration/v1/accounts';

    private const PATH_CATALOG = '/integration/v1/catalog';

    private const STATUS_OK = 'success';

    private const ORDER_SUCCEEDED = 'Succeeded';

    /** Failed 失败、Cancel 取消，都是终态失败 */
    private const ORDER_FAILED = ['Failed', 'Cancel'];

    /** rechargeStatus 3 部分成功：既不能判成功也不能退款，留给人工核实 */
    private const RECHARGE_PARTIAL = 3;

    /** 商品列表 brandName 里带运营商名时才认运营商 */
    private const OPERATORS = ['移动' => Operator::Cmcc, '联通' => Operator::Cucc, '电信' => Operator::Ctcc, '广电' => Operator::Cbn];

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'turbo';
    }

    public function name(): string
    {
        return 'Turbo TOPUP（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口域名', 'type' => 'text', 'required' => true, 'placeholder' => 'https://上游域名'],
            ['key' => 'access_key', 'label' => 'accessKey', 'type' => 'text', 'required' => true],
            ['key' => 'secret_key', 'label' => 'secretKey', 'type' => 'secret', 'required' => true],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, self::PATH_CHECKOUT, [
            'productId' => $request->externalCode,
            'phone' => $request->mobile,
            'externalOrderNo' => $request->attemptNo,
            'notificationUrl' => $request->config['notify_url'] ?? '',
        ], 10.0);
        $body = $this->decode($response['body']);
        if ($body === null) {
            // 返回无法解析、没有 status：没有拿到返回值，不知道建没建单，转异常人工处理
            return RechargeResult::callFailed('下单没有拿到上游返回，转异常人工处理：' . $this->describe($body, $response), $sent, $response['body']);
        }
        if ($body['status'] !== self::STATUS_OK) {
            return RechargeResult::failed('上游拒单：' . $this->describe($body, $response), $request->supplierOrderNo, $sent, $response['body']);
        }
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        return $this->conclude($data, $this->orderNo($data) ?? $request->supplierOrderNo, $sent, $response['body']);
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        return $this->queryOrder($request->config, $request->attemptNo, $request->supplierOrderNo);
    }

    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        $params = json_decode((string) $request->getBody(), true);
        if (! is_array($params)) {
            $params = $request->getParsedBody();
        }
        $attemptNo = is_array($params) && is_scalar($params['externalOrderNo'] ?? null) ? (string) $params['externalOrderNo'] : '';
        if ($attemptNo === '') {
            return new CallbackResult(false, 'fail');
        }
        try {
            $result = $this->queryOrder($config, $attemptNo, null, true);
        } catch (Throwable) {
            // 查单出错或没拿到明确结果，回调不采用；定时查单会继续
            return new CallbackResult(false, 'fail');
        }

        return new CallbackResult(true, 'success', $attemptNo, $result->supplierOrderNo, $result);
    }

    public function balance(array $config): ?string
    {
        [, $response] = $this->call($config, self::PATH_ACCOUNTS, []);
        $body = $this->decode($response['body']);
        if ($body === null || $body['status'] !== self::STATUS_OK || ! is_scalar($body['data']['balance'] ?? null)) {
            throw new RuntimeException('查询余额失败：' . mb_substr($response['body'], 0, 200));
        }

        return (string) $body['data']['balance'];
    }

    public function upstreamProducts(array $config): array
    {
        [, $response] = $this->call($config, self::PATH_CATALOG, []);
        $body = $this->decode($response['body']);
        if ($body === null || $body['status'] !== self::STATUS_OK || ! is_array($body['data']['result'] ?? null)) {
            throw new RuntimeException('查询商品失败：' . $this->describe($body, $response));
        }

        $products = [];
        foreach ($body['data']['result'] as $item) {
            if (is_array($item) && is_scalar($item['productId'] ?? null) && (string) $item['productId'] !== '') {
                $products[] = $this->toUpstreamProduct($item);
            }
        }

        return $products;
    }

    /**
     * 按文档「请求签名详细步骤」计算签名：去掉 sign，按名升序拼 k=v&...（值不做 URL 编码），
     * 末尾拼 &secretKey=，md5 转大写。空值文档常见问题里说会导致签名无效，call() 不发空值。
     *
     * @param array<string, string> $params
     */
    public static function sign(array $params, string $secretKey): string
    {
        unset($params['sign']);
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        $pairs[] = 'secretKey=' . $secretKey;

        return strtoupper(md5(implode('&', $pairs)));
    }

    /**
     * @param array<string, string> $config
     */
    private function queryOrder(array $config, string $attemptNo, ?string $supplierOrderNo, bool $throwIfUnclear = false): RechargeResult
    {
        [$sent, $response] = $this->call($config, self::PATH_ORDERS, ['orderNo' => $attemptNo]);
        $body = $this->decode($response['body']);
        $data = $body !== null && $body['status'] === self::STATUS_OK && is_array($body['data'] ?? null) ? $body['data'] : null;
        $externalOrderNo = is_scalar($data['externalOrderNo'] ?? null) ? (string) $data['externalOrderNo'] : '';
        $unclear = match (true) {
            $data === null => '查单未拿到明确结果：' . $this->describe($body, $response),
            $externalOrderNo !== $attemptNo => '查单返回的订单号对不上：' . $externalOrderNo,
            ! is_scalar($data['orderStatus'] ?? null) => '查单没有 orderStatus',
            default => null,
        };
        if ($unclear !== null) {
            if ($throwIfUnclear) {
                throw new RuntimeException($unclear);
            }

            return RechargeResult::processing($supplierOrderNo, $unclear, $sent, $response['body']);
        }

        return $this->conclude($data, $this->orderNo($data) ?? $supplierOrderNo, $sent, $response['body']);
    }

    /**
     * 按 orderStatus 判定结果；rechargeStatus=3 部分成功不判结果。
     *
     * @param array<string, mixed> $data
     */
    private function conclude(array $data, ?string $orderNo, string $sent, string $raw): RechargeResult
    {
        $orderStatus = is_scalar($data['orderStatus'] ?? null) ? (string) $data['orderStatus'] : '';
        if (is_numeric($data['rechargeStatus'] ?? null) && (int) $data['rechargeStatus'] === self::RECHARGE_PARTIAL) {
            return RechargeResult::processing($orderNo, '上游部分成功（rechargeStatus=3），需人工核实', $sent, $raw);
        }
        if ($orderStatus === self::ORDER_SUCCEEDED) {
            return RechargeResult::success($orderNo, null, $sent, $raw);
        }
        if (in_array($orderStatus, self::ORDER_FAILED, true)) {
            return RechargeResult::failed('上游充值失败（' . $orderStatus . '）', $orderNo, $sent, $raw);
        }

        // Created / Processing 和表外的状态
        return RechargeResult::processing($orderNo, null, $sent, $raw);
    }

    /**
     * 带上 accessKey 和签名发 JSON POST；空值不发（比如没配回调域名时的 notificationUrl）。
     *
     * @param array<string, string> $config
     * @param array<string, string> $business
     * @return array{0: string, 1: array{status: int, body: string}} 实际发送的参数（不含 sign）、响应
     */
    private function call(array $config, string $path, array $business, float $timeout = 5.0): array
    {
        $params = array_filter(['accessKey' => $config['access_key'] ?? ''] + $business, static fn (string $v) => $v !== '');
        $url = rtrim($config['api_url'] ?? '', '/') . $path;
        $response = $this->http->postJson($url, $params + ['sign' => self::sign($params, $config['secret_key'] ?? '')], $timeout);

        return [(string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $response];
    }

    /**
     * 没有 status 算没拿到返回值；HTTP 4xx / 5xx 只要带着可解析的 status 也按上游的回复处理。
     *
     * @return null|array<string, mixed>
     */
    private function decode(string $raw): ?array
    {
        $body = json_decode($raw, true);
        if (! is_array($body) || ! is_string($body['status'] ?? null) || $body['status'] === '') {
            return null;
        }

        return $body;
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
        $brand = is_scalar($item['brandId']['brandName'] ?? null) ? trim((string) $item['brandId']['brandName']) : '';
        $operators = [];
        foreach (self::OPERATORS as $name => $operator) {
            if ($brand !== '' && str_contains($brand, $name)) {
                $operators[] = $operator->value;
            }
        }
        $notes = [];
        foreach (is_array($item['cateId'] ?? null) ? $item['cateId'] : [] as $cate) {
            if (is_array($cate) && is_scalar($cate['cateName'] ?? null) && trim((string) $cate['cateName']) !== '') {
                $notes[] = trim((string) $cate['cateName']);
            }
        }
        if (is_scalar($item['pType'] ?? null) && trim((string) $item['pType']) !== '') {
            $notes[] = trim((string) $item['pType']);
        }
        $faceValue = $item['faceValue'] ?? null;

        return new UpstreamProduct(
            (string) $item['productId'],
            is_scalar($item['productName'] ?? null) ? (string) $item['productName'] : '',
            is_numeric($item['price'] ?? null) ? (string) $item['price'] : null,
            is_numeric($faceValue) && (string) (int) $faceValue === (string) $faceValue ? (int) $faceValue : null,
            $operators,
            ($item['status'] ?? true) === true,
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
        $code = is_scalar($body['error']['code'] ?? null) ? (string) $body['error']['code'] : $body['status'];
        $details = is_scalar($body['error']['details'] ?? null) ? trim((string) $body['error']['details']) : '';
        $message = $details !== '' ? $details : (is_scalar($body['message'] ?? null) ? trim((string) $body['message']) : '');

        return '[' . $code . '] ' . $message;
    }
}
