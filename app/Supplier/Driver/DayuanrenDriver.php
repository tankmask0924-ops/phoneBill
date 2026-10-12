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
 * 大猿人充值系统，文档见 docs/大猿人API接口.md（第 9 节是对接方式和结果判定）。
 *
 * - out_trade_num 传提交单号 attempt_no，product_id 传供应商商品编码，amount 传面值让上游校验；
 * - 全部接口都是表单 POST；签名：参数按名升序拼 k=v&...（不做 URL 编码），末尾拼 &apikey=，md5 转大写；
 * - 回调带签名，按收到的全部参数验签并核对 userid，通过才采用；
 * - 下单 errno 非 0 文档写的是「提交失败」，按没建单处理；拿不到可解析的返回时不知道建没建单，按处理中等查单；
 * - state 3 部分成功不能按成功也不能全额退款，保持处理中，到时转异常人工处理。
 */
class DayuanrenDriver implements SupplierDriverInterface, ProvidesUpstreamProducts
{
    private const PATH_RECHARGE = '/yrapi.php/index/recharge';

    private const PATH_QUERY = '/yrapi.php/index/check';

    private const PATH_USER = '/yrapi.php/index/user';

    private const PATH_PRODUCTS = '/yrapi.php/index/product';

    private const ERRNO_OK = '0';

    /** 上游 isp：1 移动、2 电信、3 联通，4 虚拟不对应我们的运营商 */
    private const OPERATORS = ['1' => Operator::Cmcc, '2' => Operator::Ctcc, '3' => Operator::Cucc];

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'dayuanren';
    }

    public function name(): string
    {
        return '大猿人 API（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口域名', 'type' => 'text', 'required' => true, 'placeholder' => 'https://上游域名'],
            ['key' => 'userid', 'label' => '商户ID（userid）', 'type' => 'text', 'required' => true],
            ['key' => 'apikey', 'label' => 'apikey', 'type' => 'secret', 'required' => true],
            ['key' => 'notify_url', 'label' => '回调地址', 'type' => 'text', 'required' => true, 'placeholder' => 'https://我方域名/notify/supplier/供应商编码'],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, self::PATH_RECHARGE, [
            'out_trade_num' => $request->attemptNo,
            'product_id' => $request->externalCode,
            'mobile' => $request->mobile,
            'notify_url' => $request->config['notify_url'] ?? '',
            'amount' => (string) $request->faceValue,
        ], 10.0);
        $body = $this->decode($response['body']);
        if ($body === null || $response['status'] !== 200) {
            // 文档：HTTP 非 200、没有响应体时无法判断是否下单成功，只能等查单确认
            return RechargeResult::processing($request->supplierOrderNo, '下单返回无法解析，等待查单：' . $this->describe($body, $response), $sent, $response['body']);
        }
        if ($body['errno'] !== self::ERRNO_OK) {
            return RechargeResult::failed('上游拒单：' . $this->describe($body, $response), $request->supplierOrderNo, $sent, $response['body']);
        }
        $orderNo = is_array($body['data'] ?? null) && is_scalar($body['data']['order_number'] ?? null) && $body['data']['order_number'] !== ''
            ? (string) $body['data']['order_number']
            : $request->supplierOrderNo;

        return RechargeResult::processing($orderNo, null, $sent, $response['body']);
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, self::PATH_QUERY, ['out_trade_nums' => $request->attemptNo]);
        $body = $this->decode($response['body']);
        $order = $body !== null && $body['errno'] === self::ERRNO_OK ? $this->findOrder($body['data'] ?? null, $request->attemptNo) : null;
        if ($order === null || ! is_numeric($order['state'] ?? null)) {
            return RechargeResult::processing($request->supplierOrderNo, '查单未拿到明确结果：' . $this->describe($body, $response), $sent, $response['body']);
        }
        $orderNo = is_scalar($order['order_number'] ?? null) && $order['order_number'] !== '' ? (string) $order['order_number'] : $request->supplierOrderNo;

        return $this->stateResult((int) $order['state'], null, $orderNo, $sent, $response['body']);
    }

    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        $params = $request->getParsedBody();
        if (! is_array($params) || $params === []) {
            parse_str((string) $request->getBody(), $params);
        }
        $params = array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $params);
        $attemptNo = $params['out_trade_num'] ?? '';
        $given = strtoupper($params['sign'] ?? '');
        $apikey = $config['apikey'] ?? '';
        if ($attemptNo === '' || $given === '' || $apikey === '' || ! hash_equals(self::sign($params, $apikey), $given)) {
            return new CallbackResult(false, 'fail');
        }
        if (($params['userid'] ?? '') !== ($config['userid'] ?? '') || ! is_numeric($params['state'] ?? null)) {
            return new CallbackResult(false, 'fail');
        }
        $orderNo = ($params['order_number'] ?? '') === '' ? null : $params['order_number'];
        $raw = (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new CallbackResult(true, 'success', $attemptNo, $orderNo, $this->stateResult((int) $params['state'], $params['remark'] ?? null, $orderNo, null, $raw));
    }

    public function balance(array $config): ?string
    {
        [, $response] = $this->call($config, self::PATH_USER, []);
        $body = $this->decode($response['body']);
        if ($body === null || $body['errno'] !== self::ERRNO_OK || ! is_scalar($body['data']['balance'] ?? null)) {
            throw new RuntimeException('查询余额失败：' . mb_substr($response['body'], 0, 200));
        }

        return (string) $body['data']['balance'];
    }

    /**
     * 产品列表按分类分组返回，摊平。接口不给面值，要管理员自己填。
     */
    public function upstreamProducts(array $config): array
    {
        [, $response] = $this->call($config, self::PATH_PRODUCTS, []);
        $body = $this->decode($response['body']);
        if ($body === null || $body['errno'] !== self::ERRNO_OK || ! is_array($body['data'] ?? null)) {
            throw new RuntimeException('查询商品失败：' . $this->describe($body, $response));
        }

        $products = [];
        foreach ($body['data'] as $category) {
            foreach (is_array($category) && is_array($category['products'] ?? null) ? $category['products'] : [] as $item) {
                if (is_array($item) && is_scalar($item['id'] ?? null) && (string) $item['id'] !== '') {
                    $products[] = $this->toUpstreamProduct($item, is_scalar($category['cate'] ?? null) ? (string) $category['cate'] : '');
                }
            }
        }

        return $products;
    }

    /**
     * 按上游文档「签名说明」计算签名，请求签名和回调验签共用：去掉 sign，按名升序，
     * 和上游示例一样用 http_build_query 再 urldecode（不做 URL 编码），拼上 &apikey=，md5 转大写。
     *
     * @param array<string, string> $params
     */
    public static function sign(array $params, string $apikey): string
    {
        unset($params['sign']);
        ksort($params, SORT_STRING);

        return strtoupper(md5(urldecode(http_build_query($params)) . '&apikey=' . $apikey));
    }

    /**
     * 查单返回的 data 可能是单个订单，也可能是订单列表，按单号找出这一笔。
     *
     * @return null|array<string, mixed>
     */
    private function findOrder(mixed $data, string $attemptNo): ?array
    {
        if (! is_array($data)) {
            return null;
        }
        foreach (array_is_list($data) ? $data : [$data] as $item) {
            if (is_array($item) && is_scalar($item['out_trade_num'] ?? null) && (string) $item['out_trade_num'] === $attemptNo) {
                return $item;
            }
        }

        return null;
    }

    /**
     * 查单和回调的 state：-1 取消、0 充值中、1 成功、2 失败、3 部分成功。
     */
    private function stateResult(int $state, ?string $remark, ?string $orderNo, ?string $request, string $raw): RechargeResult
    {
        $remark = trim((string) $remark);

        return match ($state) {
            1 => RechargeResult::success($orderNo, null, $request, $raw),
            -1 => RechargeResult::failed($remark === '' ? '上游已取消' : $remark, $orderNo, $request, $raw),
            2 => RechargeResult::failed($remark === '' ? '上游充值失败' : $remark, $orderNo, $request, $raw),
            3 => RechargeResult::processing($orderNo, '上游部分成功，需要人工核对到账金额后处理', $request, $raw),
            default => RechargeResult::processing($orderNo, null, $request, $raw),
        };
    }

    /**
     * @param array<string, mixed> $item
     */
    private function toUpstreamProduct(array $item, string $category): UpstreamProduct
    {
        $operators = [];
        foreach (preg_split('/\D+/', is_scalar($item['isp'] ?? null) ? (string) $item['isp'] : '', -1, PREG_SPLIT_NO_EMPTY) as $isp) {
            if (isset(self::OPERATORS[$isp])) {
                $operators[] = self::OPERATORS[$isp]->value;
            }
        }
        $note = array_filter([
            $category,
            is_scalar($item['desc'] ?? null) ? trim((string) $item['desc']) : '',
            is_scalar($item['ys_tag'] ?? null) ? trim((string) $item['ys_tag']) : '',
        ], static fn ($v) => $v !== '');

        return new UpstreamProduct(
            (string) $item['id'],
            is_scalar($item['name'] ?? null) ? (string) $item['name'] : '',
            is_scalar($item['price'] ?? null) && $item['price'] !== '' ? (string) $item['price'] : null,
            null,
            array_values(array_intersect(Operator::values(), $operators)),
            is_scalar($item['api_open'] ?? null) && (string) $item['api_open'] === '1',
            $note === [] ? null : implode(' · ', $note),
        );
    }

    /**
     * 带上 userid 和签名发表单请求。
     *
     * @param array<string, string> $config
     * @param array<string, string> $business
     * @return array{0: string, 1: array{status: int, body: string}} 实际发送的参数（不含 sign）、响应
     */
    private function call(array $config, string $path, array $business, float $timeout = 5.0): array
    {
        $params = $business + ['userid' => $config['userid'] ?? ''];
        $url = rtrim($config['api_url'] ?? '', '/') . $path;
        $response = $this->http->postForm($url, $params + ['sign' => self::sign($params, $config['apikey'] ?? '')], $timeout);

        return [(string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $response];
    }

    /**
     * errno 统一转成字符串（文档里是字符串，示例里是数字）。
     *
     * @return null|array<string, mixed>
     */
    private function decode(string $raw): ?array
    {
        $body = json_decode($raw, true);
        if (! is_array($body) || ! is_scalar($body['errno'] ?? null)) {
            return null;
        }
        $body['errno'] = (string) $body['errno'];

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

        return '[' . $body['errno'] . '] ' . (is_scalar($body['errmsg'] ?? null) ? (string) $body['errmsg'] : '');
    }
}
