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
 * 邻显科技接口，文档见 docs/邻显科技.md（最后是对接方式和结果判定表）。只接话费：下单、查单、余额、商品列表、回调。
 *
 * - out_order_no 传提交单号 attempt_no，mobile 传手机号，product_id 传供应商商品编码（上游产品 id）；
 * - 全部接口表单 POST；签名：去掉 sign，按名 ASCII 升序用 http_build_query 拼成 URI 参数（空值也参与），
 *   末尾拼 &secret=APPSECRET，md5 转大写；
 * - 下单只有 code=0 算受理；上游有返回、code 是别的一律判失败换下一家；
 *   网络异常、HTTP 出错、返回无法解析或没有 code（没有拿到返回值）按接口异常处理，订单立即转异常人工处理；
 * - 查单 order_status FINISH 成功、FAILED 失败、PASS / RUNNING 处理中；40001 无订单只记录，订单状态不动；
 * - 回调地址不随单传，要在上游后台「系统管理—查看设置」里填自动生成的回调地址；回调按收到的全部字段（除 sign）验签，
 *   FINISH / FAILED 才处理订单，验签通过回 success。
 */
class LinxianDriver implements SupplierDriverInterface, ProvidesUpstreamProducts
{
    private const PATH_RECHARGE = '/api/recharge';

    private const PATH_ORDER = '/api/order';

    private const PATH_BALANCE = '/api/balance';

    private const PATH_PRODUCT = '/api/product';

    private const CODE_OK = '0';

    /** 查单时无订单：只记录，订单状态不动 */
    private const CODE_NOT_FOUND = '40001';

    private const STATUS_SUCCESS = 'FINISH';

    private const STATUS_FAILED = 'FAILED';

    /** 上游 operator 是中文，多个以逗号隔开 */
    private const OPERATORS = ['移动' => Operator::Cmcc, '联通' => Operator::Cucc, '电信' => Operator::Ctcc, '广电' => Operator::Cbn];

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'linxian';
    }

    public function name(): string
    {
        return '邻显科技 API（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口域名', 'type' => 'text', 'required' => true, 'placeholder' => 'http://上游域名'],
            ['key' => 'appid', 'label' => 'APPID', 'type' => 'text', 'required' => true],
            ['key' => 'secret', 'label' => 'APPSECRET', 'type' => 'secret', 'required' => true],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, self::PATH_RECHARGE, [
            'out_order_no' => $request->attemptNo,
            'mobile' => $request->mobile,
            'product_id' => $request->externalCode,
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
        [$sent, $response] = $this->call($request->config, self::PATH_ORDER, ['out_order_no' => $request->attemptNo]);
        $body = $this->decode($response['body']);
        if ($body !== null && $body['code'] === self::CODE_NOT_FOUND) {
            return RechargeResult::processing($request->supplierOrderNo, '查单：上游无订单，只记录：' . $this->describe($body, $response), $sent, $response['body']);
        }
        $data = $body !== null && $body['code'] === self::CODE_OK && is_array($body['data'] ?? null) ? $body['data'] : null;
        $status = is_scalar($data['order_status'] ?? null) ? strtoupper((string) $data['order_status']) : null;
        if ($status === null) {
            return RechargeResult::processing($request->supplierOrderNo, '查单未拿到明确结果：' . $this->describe($body, $response), $sent, $response['body']);
        }
        $outOrderNo = is_scalar($data['out_order_no'] ?? null) ? (string) $data['out_order_no'] : '';
        if ($outOrderNo !== '' && $outOrderNo !== $request->attemptNo) {
            return RechargeResult::processing($request->supplierOrderNo, '查单返回的订单号对不上：' . $outOrderNo, $sent, $response['body']);
        }
        $orderNo = $this->orderNo($data) ?? $request->supplierOrderNo;

        return match ($status) {
            self::STATUS_SUCCESS => RechargeResult::success($orderNo, null, $sent, $response['body']),
            self::STATUS_FAILED => RechargeResult::failed('上游充值失败', $orderNo, $sent, $response['body']),
            // PASS 已提交、RUNNING 充值中、表外的状态
            default => RechargeResult::processing($orderNo, null, $sent, $response['body']),
        };
    }

    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        $params = $request->getParsedBody();
        if (! is_array($params) || $params === []) {
            $raw = (string) $request->getBody();
            $params = json_decode($raw, true);
            if (! is_array($params)) {
                parse_str($raw, $params);
            }
        }
        $params = array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $params);
        $attemptNo = $params['out_order_no'] ?? '';
        $given = strtoupper($params['sign'] ?? '');
        $secret = $config['secret'] ?? '';
        if ($attemptNo === '' || $given === '' || $secret === '' || ! hash_equals(self::sign($params, $secret), $given)) {
            return new CallbackResult(false, 'fail');
        }
        unset($params['sign']);
        $raw = (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $orderNo = ($params['order_no'] ?? '') === '' ? null : $params['order_no'];
        $result = match (strtoupper($params['order_status'] ?? '')) {
            self::STATUS_SUCCESS => RechargeResult::success($orderNo, null, null, $raw),
            self::STATUS_FAILED => RechargeResult::failed(trim($params['error_msg'] ?? '') ?: '上游充值失败', $orderNo, null, $raw),
            default => RechargeResult::processing($orderNo, '回调 order_status 未确认：' . ($params['order_status'] ?? ''), null, $raw),
        };

        return new CallbackResult(true, 'success', $attemptNo, $orderNo, $result);
    }

    public function balance(array $config): ?string
    {
        [, $response] = $this->call($config, self::PATH_BALANCE, []);
        $body = $this->decode($response['body']);
        if ($body === null || $body['code'] !== self::CODE_OK || ! is_scalar($body['data']['balance'] ?? null)) {
            throw new RuntimeException('查询余额失败：' . mb_substr($response['body'], 0, 200));
        }

        return (string) $body['data']['balance'];
    }

    public function upstreamProducts(array $config): array
    {
        [, $response] = $this->call($config, self::PATH_PRODUCT, []);
        $body = $this->decode($response['body']);
        if ($body === null || $body['code'] !== self::CODE_OK || ! is_array($body['data']['list'] ?? null)) {
            throw new RuntimeException('查询商品失败：' . $this->describe($body, $response));
        }

        $products = [];
        foreach ($body['data']['list'] as $item) {
            if (is_array($item) && is_scalar($item['id'] ?? null) && (string) $item['id'] !== '') {
                $products[] = $this->toUpstreamProduct($item);
            }
        }

        return $products;
    }

    /**
     * 按文档「签名生成」计算签名：去掉 sign，按名 ASCII 升序用 http_build_query 拼接（空值也参与），
     * 末尾拼 &secret=APPSECRET，md5 转大写。
     *
     * @param array<string, string> $params
     */
    public static function sign(array $params, string $secret): string
    {
        unset($params['sign']);
        ksort($params, SORT_STRING);

        return strtoupper(md5(http_build_query($params) . '&secret=' . $secret));
    }

    /**
     * 带上 appid 和签名发表单 POST。
     *
     * @param array<string, string> $config
     * @param array<string, string> $business
     * @return array{0: string, 1: array{status: int, body: string}} 实际发送的参数（不含 sign）、响应
     */
    private function call(array $config, string $path, array $business, float $timeout = 5.0): array
    {
        $params = ['appid' => $config['appid'] ?? ''] + $business;
        $url = rtrim($config['api_url'] ?? '', '/') . $path;
        $response = $this->http->postForm($url, $params + ['sign' => self::sign($params, $config['secret'] ?? '')], $timeout);

        return [(string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $response];
    }

    /**
     * code 统一转成字符串；没有 code 算没拿到返回值。
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
        $operators = [];
        foreach (explode(',', is_scalar($item['operator'] ?? null) ? (string) $item['operator'] : '') as $name) {
            if (isset(self::OPERATORS[trim($name)])) {
                $operators[] = self::OPERATORS[trim($name)]->value;
            }
        }
        $unit = is_scalar($item['unit'] ?? null) ? trim((string) $item['unit']) : '';
        $capacity = $item['capacity'] ?? null;
        $region = is_scalar($item['region'] ?? null) ? trim((string) $item['region']) : '';

        return new UpstreamProduct(
            (string) $item['id'],
            is_scalar($item['name'] ?? null) ? (string) $item['name'] : '',
            is_numeric($item['price'] ?? null) ? (string) $item['price'] : null,
            // 只有单位是「元」的容量才是话费面值
            $unit === '元' && is_numeric($capacity) && (string) (int) $capacity === (string) $capacity ? (int) $capacity : null,
            array_values(array_intersect(Operator::values(), $operators)),
            true,
            $region === '' ? null : $region,
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
        $message = is_scalar($body['message'] ?? null) ? trim((string) $body['message']) : '';

        return '[' . $body['code'] . '] ' . $message;
    }
}
