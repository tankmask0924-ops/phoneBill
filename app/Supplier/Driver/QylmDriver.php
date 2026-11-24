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
 * QYLM 卡站平台代理端下单接口，文档见 docs/QYLM开放接口对接文档-代理端下单-V1.0.md（最后是对接方式和结果判定表）。
 * 只接话费：商品列表、下单、查单、余额；上游没有回调，结果靠查单。
 *
 * - product_id 传供应商商品编码（上游商品 ID），params 传手机号；上游没有接入方单号，只能按它返回的 order_no 查单；
 * - 鉴权放在请求头：X-Agent-Id、秒级 X-Timestamp、X-Nonce、X-Sign = hmac_sha256(agent_id\nMETHOD\nURI\ntimestamp\nnonce\nraw_body)，十六进制小写；
 * - 下单 code=0 且带 order_no 算受理；上游有返回、code 是别的（401 签名、404 商品、422 参数 / 余额不足等）一律判失败换下一家；
 *   网络异常、返回无法解析（没有拿到返回值），或说成功却没给 order_no（查不了单）按接口异常处理，订单立即转异常人工处理；
 * - 查单 success 成功；failed / cancelled / refunded / unpaid 失败（上游失败会自动退款）；partial_refund 部分退款不判结果，留给人工核实；
 *   404 订单不存在只记录：单号是上游自己返回的，查不到不正常，不能判失败。
 */
class QylmDriver implements SupplierDriverInterface, ProvidesUpstreamProducts
{
    private const PATH_PRODUCTS = '/products';

    private const PATH_ORDERS = '/orders';

    private const PATH_ACCOUNT = '/account';

    private const CODE_OK = '0';

    /** 查单时订单不存在：只记录，订单状态不动 */
    private const CODE_NOT_FOUND = '404';

    private const STATUS_SUCCESS = 'success';

    /** 终态失败：充值失败、已取消、已退款、取消交易 */
    private const STATUS_FAILED = ['failed', 'cancelled', 'refunded', 'unpaid'];

    /** 部分退款：不能判成功也不能全额退款，留给人工核实 */
    private const STATUS_PARTIAL_REFUND = 'partial_refund';

    /** 商品列表每页最多 100 条 */
    private const PRODUCT_PAGE_SIZE = 100;

    private const PRODUCT_MAX_PAGES = 50;

    /** 上游商品列表没有运营商字段，商品名里带运营商名时才认 */
    private const OPERATORS = ['移动' => Operator::Cmcc, '联通' => Operator::Cucc, '电信' => Operator::Ctcc, '广电' => Operator::Cbn];

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'qylm';
    }

    public function name(): string
    {
        return 'QYLM 卡站（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口根地址', 'type' => 'text', 'required' => true, 'placeholder' => 'http://上游地址/api/open/v1'],
            ['key' => 'agent_id', 'label' => '接口 ID（代理账号 ID）', 'type' => 'text', 'required' => true],
            ['key' => 'api_key', 'label' => '接口 KEY', 'type' => 'secret', 'required' => true],
            ['key' => 'trade_password', 'label' => '交易密码', 'type' => 'secret', 'required' => false, 'placeholder' => '上游账号开启了交易密码时填写'],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        $payload = ['product_id' => ctype_digit($request->externalCode) ? (int) $request->externalCode : $request->externalCode, 'params' => $request->mobile];
        $tradePassword = $request->config['trade_password'] ?? '';
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // 原始报文里不留交易密码
        $sent = $body;
        if ($tradePassword !== '') {
            $payload['trade_password'] = $tradePassword;
            $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $response = $this->call($request->config, 'POST', self::PATH_ORDERS, $body, 10.0);
        $decoded = $this->decode($response['body']);
        if ($decoded === null) {
            // 返回无法解析：没有拿到返回值，不知道建没建单，转异常人工处理
            return RechargeResult::callFailed('下单没有拿到上游返回，转异常人工处理：' . $this->describe($decoded, $response), $sent, $response['body']);
        }
        if ($decoded['code'] !== self::CODE_OK) {
            return RechargeResult::failed('上游拒单：' . $this->describe($decoded, $response), $request->supplierOrderNo, $sent, $response['body']);
        }
        $orderNo = $this->orderNo($decoded['data'] ?? null);
        if ($orderNo === null) {
            // 上游说成功、已扣款，但没有订单号就查不了单，转异常人工处理
            return RechargeResult::callFailed('下单成功但上游没有返回订单号，无法查单，转异常人工处理', $sent, $response['body']);
        }

        return RechargeResult::processing($orderNo, null, $sent, $response['body']);
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        $orderNo = $request->supplierOrderNo;
        if ($orderNo === null || ! ctype_alnum($orderNo)) {
            return RechargeResult::processing($orderNo, '没有上游订单号（下单时没拿到返回），无法查单，请到上游核实');
        }
        $path = self::PATH_ORDERS . '/' . $orderNo;
        $response = $this->call($request->config, 'GET', $path, '');
        $sent = 'GET ' . $path;
        $body = $this->decode($response['body']);
        if ($body !== null && $body['code'] === self::CODE_NOT_FOUND) {
            return RechargeResult::processing($orderNo, '查单：上游查不到这笔订单，只记录：' . $this->describe($body, $response), $sent, $response['body']);
        }
        $data = $body !== null && $body['code'] === self::CODE_OK && is_array($body['data'] ?? null) ? $body['data'] : null;
        if ($data === null || ! is_string($data['status'] ?? null) || $this->orderNo($data) !== $orderNo) {
            return RechargeResult::processing($orderNo, '查单未拿到明确结果：' . $this->describe($body, $response), $sent, $response['body']);
        }
        $message = is_scalar($data['message'] ?? null) ? trim((string) $data['message']) : '';

        return match (true) {
            $data['status'] === self::STATUS_SUCCESS => RechargeResult::success($orderNo, null, $sent, $response['body']),
            in_array($data['status'], self::STATUS_FAILED, true) => RechargeResult::failed(
                ($message === '' ? '上游充值失败' : $message) . '（' . $data['status'] . '）',
                $orderNo,
                $sent,
                $response['body']
            ),
            $data['status'] === self::STATUS_PARTIAL_REFUND => RechargeResult::processing($orderNo, '上游部分退款（partial_refund），需人工核实到账金额后处理', $sent, $response['body']),
            // pending / processing / awaiting 和表外的状态
            default => RechargeResult::processing($orderNo, null, $sent, $response['body']),
        };
    }

    /**
     * 上游没有结果回调，结果以查单为准。
     */
    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        return new CallbackResult(false, 'fail');
    }

    public function balance(array $config): ?string
    {
        $response = $this->call($config, 'GET', self::PATH_ACCOUNT, '');
        $body = $this->decode($response['body']);
        if ($body === null || $body['code'] !== self::CODE_OK || ! is_scalar($body['data']['balance'] ?? null)) {
            throw new RuntimeException('查询余额失败：' . mb_substr($response['body'], 0, 200));
        }

        return (string) $body['data']['balance'];
    }

    /**
     * 商品列表分页返回（每页最多 100 条），逐页取完。
     */
    public function upstreamProducts(array $config): array
    {
        $products = [];
        for ($page = 1; $page <= self::PRODUCT_MAX_PAGES; ++$page) {
            $response = $this->call($config, 'GET', self::PATH_PRODUCTS . '?page=' . $page . '&size=' . self::PRODUCT_PAGE_SIZE, '');
            $body = $this->decode($response['body']);
            if ($body === null || $body['code'] !== self::CODE_OK || ! is_array($body['data']['items'] ?? null)) {
                throw new RuntimeException('查询商品失败：' . $this->describe($body, $response));
            }
            $items = $body['data']['items'];
            foreach ($items as $item) {
                if (is_array($item) && is_scalar($item['id'] ?? null) && (string) $item['id'] !== '') {
                    $products[] = $this->toUpstreamProduct($item);
                }
            }
            $total = is_numeric($body['data']['total'] ?? null) ? (int) $body['data']['total'] : 0;
            if (count($items) < self::PRODUCT_PAGE_SIZE || $page * self::PRODUCT_PAGE_SIZE >= $total) {
                break;
            }
        }

        return $products;
    }

    /**
     * 按文档第 01 章「签名串」计算 X-Sign：agent_id、大写 METHOD、URI（含查询字符串）、timestamp、nonce、
     * 原始请求体（GET 为空串）用 \n 连接，接口 KEY 做 HMAC-SHA256，十六进制小写。
     */
    public static function sign(string $agentId, string $method, string $uri, string $timestamp, string $nonce, string $body, string $apiKey): string
    {
        return hash_hmac('sha256', implode("\n", [$agentId, strtoupper($method), $uri, $timestamp, $nonce, $body]), $apiKey);
    }

    /**
     * 带签名请求头发请求；签名用的 URI 取最终请求地址的路径和查询字符串，和实际发送的完全一致。
     *
     * @param array<string, string> $config
     * @return array{status: int, body: string}
     */
    private function call(array $config, string $method, string $path, string $body, float $timeout = 5.0): array
    {
        $url = rtrim($config['api_url'] ?? '', '/') . $path;
        $query = parse_url($url, PHP_URL_QUERY);
        $uri = (parse_url($url, PHP_URL_PATH) ?: '/') . (is_string($query) && $query !== '' ? '?' . $query : '');
        $agentId = $config['agent_id'] ?? '';
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $headers = [
            'X-Agent-Id' => $agentId,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Sign' => self::sign($agentId, $method, $uri, $timestamp, $nonce, $body, $config['api_key'] ?? ''),
        ];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->http->send($method, $url, $headers, $body, $timeout);
    }

    /**
     * 上游 HTTP 状态码和 code 一致（成功是 201），按响应体里的 code 判断，code 统一转成字符串。
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

    private function orderNo(mixed $data): ?string
    {
        return is_array($data) && is_scalar($data['order_no'] ?? null) && (string) $data['order_no'] !== '' ? (string) $data['order_no'] : null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function toUpstreamProduct(array $item): UpstreamProduct
    {
        $name = is_scalar($item['name'] ?? null) ? (string) $item['name'] : '';
        $operators = [];
        foreach (self::OPERATORS as $label => $operator) {
            if (str_contains($name, $label)) {
                $operators[] = $operator->value;
            }
        }
        $notes = array_filter([
            is_scalar($item['category_name'] ?? null) ? trim((string) $item['category_name']) : '',
            is_scalar($item['order_template'] ?? null) ? '下单参数：' . trim((string) $item['order_template']) : '',
        ], static fn ($v) => $v !== '' && $v !== '下单参数：');
        // stock 为 null 表示不限库存，0 表示没货
        $stock = $item['stock'] ?? null;

        return new UpstreamProduct(
            (string) $item['id'],
            $name,
            is_numeric($item['price'] ?? null) ? (string) $item['price'] : null,
            null,
            $operators,
            $stock === null || (is_numeric($stock) && (int) $stock > 0),
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

        return '[' . $body['code'] . '] ' . (is_scalar($body['msg'] ?? null) ? trim((string) $body['msg']) : '');
    }
}
