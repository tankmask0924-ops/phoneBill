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
use DateTimeImmutable;
use DateTimeZone;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * 智信科技虚拟充值接口，文档见 docs/智信科技平台API接口文档.md（最后是对接方式和结果判定表）。只接话费：下单、查单、余额、回调。
 *
 * - client_order_no 传提交单号 attempt_no，phone_no 传手机号，product_type 固定 1（手机充值），recharge_amount 传面值（分），
 *   cp 按运营商（01 移动、02 电信、03 联通、04 广电），city_code / recharge_desc 传空，recharge_type 固定 0（预存金扣费）；
 * - 全部接口 POST JSON；签名：去掉 sign，按名升序拼 key1value1key2value2...（空值也参与），末尾拼商户密钥，md5 小写；
 *   request_time 是北京时间 yyyyMMddHHmmss，和上游相差超过 5 分钟会被拒；
 * - 下单 code=2 受理；上游有返回、code 是别的（含 625 重复订单号和表外的码）一律判失败换下一家；
 *   网络异常、HTTP 出错、返回无法解析或没有 code（没有拿到返回值）按接口异常处理，订单立即转异常人工处理；
 * - 查单要带下单时间 order_time：取提交单号开头的 14 位下单时间（上游会查这一天和前后各一天）；
 *   查单 626 订单不存在只记录，订单状态不动（下单受理了就以下单为准）；
 * - 回调是 GET，按 client_order_no、deduction_amount、elecardID、phone_no、product_type、recharge_status、up_order_no
 *   中收到的字段验签（desc、channel_type 不参与），recharge_status 2 成功、6 失败、其他不处理订单，验签通过回 SUCCESS。
 */
class ZhixinDriver implements SupplierDriverInterface
{
    private const PATH_RECHARGE = '/zxpaycore/v2/recharge';

    private const PATH_QUERY = '/zxpaycore/v2/query';

    private const PATH_BALANCE = '/zxpaycore/v2/balance';

    private const CODE_OK = '2';

    /** 查单时订单不存在：只记录，订单状态不动 */
    private const CODE_NOT_FOUND = '626';

    /** 返回码的文档说明：上游没给描述时用；下单除了 2 都按失败处理 */
    private const CODES = [
        '600' => '商户禁用,接口已关闭',
        '602' => '订单提交失败，未充值',
        '603' => '参数校验错误',
        '606' => '数据签名错误',
        '615' => '号码归属地未配置',
        '622' => '商户不存在',
        '623' => '通道维护',
        '624' => '产品未配置',
        '625' => '重复订单号',
        '626' => '订单不存在',
        '637' => '流量充值未配置',
        '751' => 'IP地址限制',
    ];

    private const STATUS_PROCESSING = '1';

    private const STATUS_SUCCESS = '2';

    private const STATUS_FAILED = '6';

    /** 上游 cp：01 移动、02 电信、03 联通、04 广电 */
    private const CPS = ['cmcc' => '01', 'ctcc' => '02', 'cucc' => '03', 'cbn' => '04'];

    /** 回调参与签名的字段（desc、channel_type 不参与） */
    private const CALLBACK_SIGNED = ['client_order_no', 'deduction_amount', 'elecardID', 'phone_no', 'product_type', 'recharge_status', 'up_order_no'];

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'zhixin';
    }

    public function name(): string
    {
        return '智信科技 API（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口域名', 'type' => 'text', 'required' => true, 'placeholder' => 'http://上游域名'],
            ['key' => 'query_url', 'label' => '查单 / 余额域名', 'type' => 'text', 'required' => false, 'placeholder' => '上游给了独立域名才填，留空同接口域名'],
            ['key' => 'mrch_no', 'label' => '商户代号（mrch_no）', 'type' => 'text', 'required' => true],
            ['key' => 'secret_key', 'label' => '商户密钥', 'type' => 'secret', 'required' => true],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, $this->apiUrl($request->config), self::PATH_RECHARGE, [
            'client_order_no' => $request->attemptNo,
            'product_type' => 1,
            'phone_no' => $request->mobile,
            'cp' => self::CPS[$request->operator] ?? '',
            'city_code' => '',
            'recharge_amount' => $request->faceValue * 100,
            'recharge_type' => 0,
            'recharge_desc' => '',
            'notify_url' => $request->config['notify_url'] ?? '',
        ], 10.0);
        $body = $this->decode($response['body']);
        if ($body === null) {
            // HTTP 出错、返回无法解析：没有拿到返回值，不知道建没建单，转异常人工处理
            return RechargeResult::callFailed('下单没有拿到上游返回，转异常人工处理：' . $this->describe($body, $response), $sent, $response['body']);
        }
        if ($body['code'] !== self::CODE_OK) {
            return RechargeResult::failed('上游拒单：' . $this->describe($body, $response), $request->supplierOrderNo, $sent, $response['body']);
        }

        return RechargeResult::processing($this->upOrderNo($body['data'] ?? null) ?? $request->supplierOrderNo, null, $sent, $response['body']);
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, $this->queryUrl($request->config), self::PATH_QUERY, [
            'client_order_no' => $request->attemptNo,
            'order_time' => self::orderTime($request->attemptNo),
        ]);
        $body = $this->decode($response['body']);
        if ($body !== null && $body['code'] === self::CODE_NOT_FOUND) {
            return RechargeResult::processing($request->supplierOrderNo, '查单：上游订单不存在，只记录：' . $this->describe($body, $response), $sent, $response['body']);
        }
        $data = $body !== null && $body['code'] === self::CODE_OK && is_array($body['data'] ?? null) ? $body['data'] : null;
        $status = is_scalar($data['recharge_status'] ?? null) ? (string) $data['recharge_status'] : null;
        if ($status === null) {
            // 603 请求数据不正确、606、751、表外的码、没拿到返回：不处理订单
            return RechargeResult::processing($request->supplierOrderNo, '查单未拿到明确结果：' . $this->describe($body, $response), $sent, $response['body']);
        }
        $orderNo = is_scalar($data['client_order_no'] ?? null) ? (string) $data['client_order_no'] : '';
        if ($orderNo !== '' && $orderNo !== $request->attemptNo) {
            return RechargeResult::processing($request->supplierOrderNo, '查单返回的订单号对不上：' . $orderNo, $sent, $response['body']);
        }
        $upOrderNo = $this->upOrderNo($data) ?? $request->supplierOrderNo;

        return match ($status) {
            self::STATUS_SUCCESS => RechargeResult::success($upOrderNo, null, $sent, $response['body']),
            self::STATUS_FAILED => RechargeResult::failed($this->desc($data) ?: '上游充值失败', $upOrderNo, $sent, $response['body']),
            self::STATUS_PROCESSING => RechargeResult::processing($upOrderNo, null, $sent, $response['body']),
            default => RechargeResult::processing($upOrderNo, '查单 recharge_status 未确认：' . $status, $sent, $response['body']),
        };
    }

    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        $params = $request->getQueryParams();
        if ($params === [] || ! isset($params['sign'])) {
            $parsed = $request->getParsedBody();
            $params = is_array($parsed) && $parsed !== [] ? $parsed : [];
        }
        $params = array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $params);
        $attemptNo = $params['client_order_no'] ?? '';
        $given = strtolower($params['sign'] ?? '');
        $key = $config['secret_key'] ?? '';
        if ($attemptNo === '' || $given === '' || $key === '' || ! hash_equals(self::callbackSign($params, $key), $given)) {
            return new CallbackResult(false, 'fail');
        }
        $raw = (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $upOrderNo = ($params['up_order_no'] ?? '') === '' ? null : $params['up_order_no'];
        $result = match ($params['recharge_status'] ?? '') {
            self::STATUS_SUCCESS => RechargeResult::success($upOrderNo, null, null, $raw),
            self::STATUS_FAILED => RechargeResult::failed($this->desc($params) ?: '上游充值失败', $upOrderNo, null, $raw),
            // 文档 8.3：其他是交易中，不处理订单
            default => RechargeResult::processing($upOrderNo, '回调 recharge_status 未确认：' . ($params['recharge_status'] ?? ''), null, $raw),
        };

        return new CallbackResult(true, 'SUCCESS', $attemptNo, $upOrderNo, $result);
    }

    public function balance(array $config): ?string
    {
        [, $response] = $this->call($config, $this->queryUrl($config), self::PATH_BALANCE, []);
        $body = $this->decode($response['body']);
        if ($body === null || $body['code'] !== self::CODE_OK || ! is_scalar($body['data']['balance'] ?? null)) {
            throw new RuntimeException('查询余额失败：' . mb_substr($response['body'], 0, 200));
        }

        return (string) $body['data']['balance'];
    }

    /**
     * 按文档第 3 节计算签名：去掉 sign，按名升序拼 key1value1key2value2...（空值也参与），末尾拼商户密钥，md5 小写。
     *
     * @param array<string, int|string> $params
     */
    public static function sign(array $params, string $secretKey): string
    {
        unset($params['sign']);
        ksort($params, SORT_STRING);
        $joined = '';
        foreach ($params as $name => $value) {
            $joined .= $name . $value;
        }

        return md5($joined . $secretKey);
    }

    /**
     * 回调签名：只取文档列出的字段里实际收到的，值按收到的原样。
     *
     * @param array<string, string> $params
     */
    public static function callbackSign(array $params, string $secretKey): string
    {
        return self::sign(array_intersect_key($params, array_flip(self::CALLBACK_SIGNED)), $secretKey);
    }

    /**
     * 查单的 order_time：提交单号开头是订单号的 14 位下单时间（YmdHis，北京时间），取不到时用当前时间
     * （上游会查 order_time 当天和前后各一天）。
     */
    public static function orderTime(string $attemptNo): string
    {
        $prefix = substr($attemptNo, 0, 14);
        $time = DateTimeImmutable::createFromFormat('!YmdHis', $prefix, new DateTimeZone('Asia/Shanghai'));
        if (strlen($prefix) === 14 && ctype_digit($prefix) && $time !== false && $time->format('YmdHis') === $prefix) {
            return $prefix;
        }

        return self::now();
    }

    /**
     * 带上 mrch_no、request_time 和签名发 JSON 请求。
     *
     * @param array<string, string> $config
     * @param array<string, int|string> $business
     * @return array{0: string, 1: array{status: int, body: string}} 实际发送的参数（不含 sign）、响应
     */
    private function call(array $config, string $baseUrl, string $path, array $business, float $timeout = 5.0): array
    {
        $params = ['mrch_no' => $config['mrch_no'] ?? '', 'request_time' => self::now()] + $business;
        $response = $this->http->postJson($baseUrl . $path, $params + ['sign' => self::sign($params, $config['secret_key'] ?? '')], $timeout);

        return [(string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $response];
    }

    /**
     * @param array<string, string> $config
     */
    private function apiUrl(array $config): string
    {
        return rtrim($config['api_url'] ?? '', '/');
    }

    /**
     * 文档 2.3.3：余额、查单可能用独立域名，没配就用接口域名。
     *
     * @param array<string, string> $config
     */
    private function queryUrl(array $config): string
    {
        $url = trim($config['query_url'] ?? '');

        return $url === '' ? $this->apiUrl($config) : rtrim($url, '/');
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('YmdHis');
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

    private function upOrderNo(mixed $data): ?string
    {
        return is_array($data) && is_scalar($data['up_order_no'] ?? null) && (string) $data['up_order_no'] !== '' ? (string) $data['up_order_no'] : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function desc(array $data): string
    {
        return is_scalar($data['desc'] ?? null) ? trim((string) $data['desc']) : '';
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

        return '[' . $body['code'] . '] ' . ($message === '' ? self::CODES[$body['code']] ?? '' : $message);
    }
}
