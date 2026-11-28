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
 * 卡速售外部接口，文档见 docs/卡速售.md（最后是对接方式、结果判定表和待确认事项）。只接话费：下单、余额、商品列表、回调；
 * **上游没有查单接口**。
 *
 * - apiOrderSn 传提交单号 attempt_no，number 传手机号，productId 传供应商商品编码（上游商品 id），buyNum 固定 1，api 固定 api；
 * - 全部接口 POST JSON：{data: 业务参数, sign, timestamp(13 位毫秒), appId, appKey}；签名只算 data：去掉 null 和空串，
 *   按名升序拼 keyvalue（没有分隔符），前面拼 appKey + timestamp，md5 小写；
 * - 回调不带签名、又没法查单确认：每单的 callbackUrl 后面加 token = HMAC-SHA256(提交单号, appKey) 前 32 位，
 *   回调时按 apiOrderSn 重算比对，对不上回 fail；
 * - 下单 code=200 且 data.success=true 算受理；code=200 但 success=false 和其他 code 判失败换下一家；
 *   网络异常、返回无法解析、没有 code、code=200 却没有 data.success 转异常人工处理；
 * - 回调 orderStatus 3 已完成成功、4 已取消失败，1 已支付 / 5 正在充值和其他处理中；
 * - 定时 / 人工查单不发请求，按处理中只记录，结果等回调；一直没回调的订单超时后人工到上游后台核实处理。
 */
class KasushouDriver implements SupplierDriverInterface, ProvidesUpstreamProducts
{
    private const PATH_CREATE_ORDER = '/prod-api/module-api/external/order/createOrder';

    private const PATH_PRODUCTS = '/prod-api/module-api/external/product/pageList';

    private const PATH_BALANCE = '/prod-api/module-api/external/userCategoryBalance/balance';

    private const CODE_OK = '200';

    private const ORDER_SUCCESS = '3';

    private const ORDER_CANCELLED = '4';

    /** 商品列表每页条数、最多翻几页 */
    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 50;

    /** 回调地址上带的校验参数名 */
    private const TOKEN_PARAM = 'token';

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'kasushou';
    }

    public function name(): string
    {
        return '卡速售（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口域名', 'type' => 'text', 'required' => true, 'placeholder' => 'https://上游域名（不含 /prod-api）'],
            ['key' => 'app_id', 'label' => 'appId', 'type' => 'text', 'required' => true],
            ['key' => 'app_key', 'label' => 'appKey', 'type' => 'secret', 'required' => true],
            ['key' => 'user_id', 'label' => '用户 ID（查余额用）', 'type' => 'text', 'required' => false, 'placeholder' => '上游提供的 userId，不填查不了余额'],
            ['key' => 'category_ids', 'label' => '余额分类 ID', 'type' => 'text', 'required' => false, 'placeholder' => '选填，多个用英文逗号分隔，如 34,35'],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        $appKey = $request->config['app_key'] ?? '';
        [$sent, $response] = $this->call($request->config, self::PATH_CREATE_ORDER, [
            'productId' => self::number($request->externalCode),
            'number' => self::number($request->mobile),
            'buyNum' => 1,
            'apiOrderSn' => $request->attemptNo,
            'callbackUrl' => self::callbackUrl($request->config['notify_url'] ?? '', $request->attemptNo, $appKey),
            'api' => 'api',
        ], 10.0);
        $body = $this->decode($response['body']);
        if ($body === null) {
            return RechargeResult::callFailed('下单没有拿到上游返回，转异常人工处理：' . $this->describe($body, $response), $sent, $response['body']);
        }
        if ($body['code'] !== self::CODE_OK) {
            return RechargeResult::failed('上游拒单：' . $this->describe($body, $response), $request->supplierOrderNo, $sent, $response['body']);
        }
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $success = $data['success'] ?? null;
        if ($success === false || $success === 'false') {
            $reason = is_scalar($data['message'] ?? null) ? trim((string) $data['message']) : '';

            return RechargeResult::failed('上游下单失败：' . ($reason === '' ? $this->describe($body, $response) : $reason), $request->supplierOrderNo, $sent, $response['body']);
        }
        if ($success !== true && $success !== 'true') {
            // code=200 却没说成没成功：不知道建没建单，又没法查单，转异常人工处理
            return RechargeResult::callFailed('下单返回里没有 data.success，不知道是否已建单，转异常人工处理：' . $this->describe($body, $response), $sent, $response['body']);
        }

        // 受理成功，结果等回调
        return RechargeResult::processing($this->orderNo($data, 'orderNo') ?? $request->supplierOrderNo, null, $sent, $response['body']);
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        // 上游没有查单接口：不发请求，按处理中只记录
        return RechargeResult::processing($request->supplierOrderNo, '上游没有查单接口，结果等回调；一直没回调的请到上游后台核实后人工处理');
    }

    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        $raw = (string) $request->getBody();
        $params = json_decode($raw, true);
        if (! is_array($params)) {
            $params = $request->getParsedBody();
        }
        $params = (is_array($params) ? $params : []) + $request->getQueryParams();
        $attemptNo = is_scalar($params['apiOrderSn'] ?? null) ? trim((string) $params['apiOrderSn']) : '';
        $token = $request->getQueryParams()[self::TOKEN_PARAM] ?? null;
        $appKey = $config['app_key'] ?? '';
        if ($attemptNo === '' || $appKey === '' || ! is_string($token) || ! hash_equals(self::token($attemptNo, $appKey), $token)) {
            return new CallbackResult(false, 'fail');
        }
        $orderNo = $this->orderNo($params, 'orderSn');

        return new CallbackResult(true, 'success', $attemptNo, $orderNo, $this->conclude($params, $orderNo, $raw === '' ? (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $raw));
    }

    public function balance(array $config): ?string
    {
        $userId = trim($config['user_id'] ?? '');
        if ($userId === '') {
            throw new RuntimeException('没填用户 ID（userId），查不了余额');
        }
        $categoryIds = trim($config['category_ids'] ?? '');
        [, $response] = $this->call($config, self::PATH_BALANCE, ['userId' => self::number($userId), 'categoryIds' => $categoryIds === '' ? null : $categoryIds]);
        $body = $this->decode($response['body']);
        if ($body === null || $body['code'] !== self::CODE_OK || ! is_numeric($body['data'] ?? null)) {
            throw new RuntimeException('查询余额失败：' . $this->describe($body, $response));
        }

        return (string) $body['data'];
    }

    public function upstreamProducts(array $config): array
    {
        $products = [];
        $seen = 0;
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            [, $response] = $this->call($config, self::PATH_PRODUCTS, ['pageNum' => $page, 'pageSize' => self::PAGE_SIZE]);
            $body = $this->decode($response['body']);
            if ($body === null || $body['code'] !== self::CODE_OK || ! is_array($body['rows'] ?? null)) {
                throw new RuntimeException('查询商品失败：' . $this->describe($body, $response));
            }
            foreach ($body['rows'] as $item) {
                if (is_array($item) && is_scalar($item['id'] ?? null) && (string) $item['id'] !== '') {
                    $products[] = $this->toUpstreamProduct($item);
                }
            }
            $seen += count($body['rows']);
            $total = is_numeric($body['total'] ?? null) ? (int) $body['total'] : 0;
            if ($body['rows'] === [] || $seen >= $total) {
                break;
            }
        }

        return $products;
    }

    /**
     * 按文档「接口签名认证方法」计算：只算 data 里的参数，去掉 null 和空串（数字 0 参与），按名升序拼 keyvalue，
     * 前面拼 appKey + timestamp，md5 小写。
     *
     * @param array<string, mixed> $data
     */
    public static function sign(array $data, string $appKey, int|string $timestamp): string
    {
        $pairs = [];
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            if ($value === null || ! is_scalar($value) || (string) $value === '') {
                continue;
            }
            $pairs[(string) $key] = (string) $value;
        }
        ksort($pairs, SORT_STRING);
        $text = '';
        foreach ($pairs as $key => $value) {
            $text .= $key . $value;
        }

        return md5($appKey . $timestamp . $text);
    }

    /**
     * 回调地址上带的校验值：只有我们知道 appKey，伪造回调算不出来。
     */
    public static function token(string $attemptNo, string $appKey): string
    {
        return substr(hash_hmac('sha256', $attemptNo, $appKey), 0, 32);
    }

    /**
     * 自动生成的回调地址后面加上这一单的 token；没配回调域名（地址为空）时原样返回。
     */
    public static function callbackUrl(string $notifyUrl, string $attemptNo, string $appKey): string
    {
        if ($notifyUrl === '') {
            return '';
        }

        return $notifyUrl . (str_contains($notifyUrl, '?') ? '&' : '?') . self::TOKEN_PARAM . '=' . self::token($attemptNo, $appKey);
    }

    /**
     * 文档里 productId、number、userId 都是数字类型：全是数字时按整数发，否则原样。签名按字符串算，结果一样。
     */
    private static function number(string $value): int|string
    {
        return preg_match('/^[1-9]\d{0,17}$/', $value) === 1 ? (int) $value : $value;
    }

    /**
     * 带上 appId、appKey、毫秒时间戳和签名发 JSON POST；data 里的空值不发。
     *
     * @param array<string, string> $config
     * @param array<string, mixed> $data
     * @return array{0: string, 1: array{status: int, body: string}} 实际发送的参数（不含 sign、appKey）、响应
     * @throws UnclearResultException 连不上、超时等，结果里带着发出的请求报文
     */
    private function call(array $config, string $path, array $data, float $timeout = 5.0): array
    {
        $data = array_filter($data, static fn ($v) => $v !== null && $v !== '');
        $appKey = $config['app_key'] ?? '';
        $timestamp = (int) floor(microtime(true) * 1000);
        $logged = ['data' => $data, 'timestamp' => $timestamp, 'appId' => $config['app_id'] ?? ''];
        $sent = (string) json_encode($logged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload = ['data' => (object) $data, 'sign' => self::sign($data, $appKey, $timestamp), 'timestamp' => $timestamp, 'appId' => $logged['appId'], 'appKey' => $appKey];
        $url = rtrim($config['api_url'] ?? '', '/') . $path;
        try {
            $response = $this->http->postJson($url, $payload, $timeout);
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
     * 按回调 orderStatus 判定：3 已完成成功，4 已取消失败，1 已支付、5 正在充值和其他处理中。
     *
     * @param array<string, mixed> $params
     */
    private function conclude(array $params, ?string $orderNo, string $raw): RechargeResult
    {
        $status = is_scalar($params['orderStatus'] ?? null) ? trim((string) $params['orderStatus']) : '';

        return match ($status) {
            self::ORDER_SUCCESS => RechargeResult::success($orderNo, null, null, $raw),
            self::ORDER_CANCELLED => RechargeResult::failed('上游订单已取消', $orderNo, null, $raw),
            default => RechargeResult::processing($orderNo, null, null, $raw),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function orderNo(array $data, string $key): ?string
    {
        return is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function toUpstreamProduct(array $item): UpstreamProduct
    {
        $name = is_scalar($item['productName'] ?? null) ? trim((string) $item['productName']) : '';
        // carrier 的取值文档没写全（只见过 1 = 移动），按商品名里的运营商名认
        $operators = [];
        foreach (Operator::cases() as $operator) {
            if (str_contains($name, $operator->label())) {
                $operators[] = $operator->value;
            }
        }
        $notes = [];
        foreach (['categoryName', 'productSn'] as $key) {
            if (is_scalar($item[$key] ?? null) && trim((string) $item[$key]) !== '') {
                $notes[] = trim((string) $item[$key]);
            }
        }
        $denomination = $item['denomination'] ?? null;
        $stock = $item['stock'] ?? null;
        $onSale = (string) ($item['status'] ?? '') === '1' && (! is_numeric($stock) || (int) $stock > 0);

        return new UpstreamProduct(
            (string) $item['id'],
            $name,
            is_numeric($item['price'] ?? null) ? (string) $item['price'] : null,
            is_numeric($denomination) && floor((float) $denomination) === (float) $denomination ? (int) $denomination : null,
            $operators,
            $onSale,
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
