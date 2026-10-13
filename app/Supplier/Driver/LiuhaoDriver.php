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
use Throwable;

/**
 * 刘浩充值接口，文档见 docs/充值API.md（下单、查单、余额、回调，最后是对接方式和待确认事项）。
 *
 * - 全部是 GET /flow/charge.aspx，按 method 区分业务；custom_number 传提交单号 attempt_no，
 *   product 传供应商商品编码（话费就是面值，比如 100），另带不参与签名的 network / area；
 * - 签名：按文档固定顺序拼 k=v&...（不排序），末尾直接拼 key，md5 小写；
 * - 下单 0000 受理、9545 订单号重复都是处理中；1111 其他原因按接口异常处理，订单立即转异常人工处理；
 *   文档列出的其他响应码按失败换下一家；表外的处理中等查单；
 * - 回调签名只有 md5(Mobile + Custom_Number + key)，不含充值状态，签名固定、换个状态就能重放，
 *   所以验签通过后再主动查一次单，以查单结果为准；
 * - 回调地址不随请求传，要在上游那边配好。
 */
class LiuhaoDriver implements SupplierDriverInterface
{
    private const PATH = '/flow/charge.aspx';

    private const CODE_OK = '0000';

    /** 订单号重复：之前已经提交过，绝不能当失败 */
    private const CODE_DUPLICATE = '9545';

    /** 其他原因：不知道建没建单，按接口异常处理，订单立即转异常人工处理 */
    private const CODE_OTHER = '1111';

    /** 下单时文档写明按失败处理的响应码 */
    private const REJECT_CODES = [
        '9522' => '手机号码有误',
        '9500' => '账号信息未找到',
        '9511' => '签名验证未通过',
        '9533' => '请求的流量包未找到',
        '9555' => '余额不足',
    ];

    /** 查单时订单不存在（文档：可处理失败） */
    private const CODE_NOT_FOUND = '2222';

    private const STATUS_SUCCESS = '2000';

    private const STATUS_FAILED = '3000';

    /** 上游 network：1 移动、2 联通、3 电信 */
    private const NETWORKS = ['cmcc' => '1', 'cucc' => '2', 'ctcc' => '3'];

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'liuhao';
    }

    public function name(): string
    {
        return '刘浩 API（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口地址', 'type' => 'text', 'required' => true, 'placeholder' => 'http://IP:端口'],
            ['key' => 'user', 'label' => '代理商账号（user）', 'type' => 'text', 'required' => true],
            ['key' => 'key', 'label' => '秘钥', 'type' => 'secret', 'required' => true],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        $signed = [
            'method' => 'charge',
            'user' => $request->config['user'] ?? '',
            'custom_number' => $request->attemptNo,
            'mobile' => $request->mobile,
            'product' => $request->externalCode,
        ];
        $optional = array_filter([
            'network' => self::NETWORKS[$request->operator] ?? '',
            'area' => $request->province,
        ], static fn ($v) => $v !== '');
        [$sent, $response] = $this->call($request->config, $signed, $optional, 10.0);
        $body = $this->decode($response['body']);
        if ($body !== null && isset(self::REJECT_CODES[$body['ResultCode']])) {
            return RechargeResult::failed('上游拒单：' . $this->describe($body, $response), $request->supplierOrderNo, $sent, $response['body']);
        }
        if ($body !== null && $body['ResultCode'] === self::CODE_OTHER) {
            return RechargeResult::callFailed('上游未受理（其他原因），转异常人工处理：' . $this->describe($body, $response), $sent, $response['body']);
        }
        if ($body !== null && $body['ResultCode'] === self::CODE_DUPLICATE) {
            return RechargeResult::processing($request->supplierOrderNo, '上游订单号已存在（之前已提交过），等待结果', $sent, $response['body']);
        }
        if ($body === null || $body['ResultCode'] !== self::CODE_OK) {
            // 表外的响应码、HTTP 出错、返回无法解析：不知道建没建单，只能等查单确认
            return RechargeResult::processing($request->supplierOrderNo, '下单未确认受理，等待查单：' . $this->describe($body, $response), $sent, $response['body']);
        }

        return RechargeResult::processing($request->supplierOrderNo, null, $sent, $response['body']);
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        return $this->queryOrder($request->config, $request->attemptNo, $request->supplierOrderNo);
    }

    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        $raw = (string) $request->getBody();
        $params = json_decode(self::stripBom($raw), true);
        if (! is_array($params)) {
            $params = $request->getParsedBody();
        }
        if (! is_array($params) || $params === []) {
            parse_str($raw, $params);
        }
        $params = array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $params);
        $attemptNo = $params['Custom_Number'] ?? '';
        $given = strtolower($params['Sign'] ?? '');
        $key = $config['key'] ?? '';
        if ($attemptNo === '' || $given === '' || $key === '' || ! hash_equals(self::callbackSign($params['Mobile'] ?? '', $attemptNo, $key), $given)) {
            return new CallbackResult(false, 'fail');
        }

        // 签名不含状态，回调里的 OrderStatus 只作参考，以查单为准；查单出错就按处理中，留给定时查单
        try {
            $result = $this->queryOrder($config, $attemptNo, null);
        } catch (Throwable $e) {
            $result = RechargeResult::processing(null, '回调后查单出错，等待定时查单：' . mb_substr($e->getMessage(), 0, 100));
        }

        return new CallbackResult(true, 'ok', $attemptNo, null, $result);
    }

    public function balance(array $config): ?string
    {
        [, $response] = $this->call($config, ['method' => 'balance', 'user' => $config['user'] ?? '']);
        $body = $this->decode($response['body']);
        if ($body === null || $body['ResultCode'] !== self::CODE_OK || ! is_scalar($body['Balance'] ?? null)) {
            throw new RuntimeException('查询余额失败：' . mb_substr($response['body'], 0, 200));
        }

        return (string) $body['Balance'];
    }

    /**
     * 请求签名：参数按传入顺序（即文档的明文顺序）拼 k=v&...，末尾直接拼秘钥，md5 小写。
     *
     * @param array<string, string> $params
     */
    public static function sign(array $params, string $key): string
    {
        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return md5(implode('&', $pairs) . $key);
    }

    /**
     * 回调签名：md5(Mobile + Custom_Number + key)，小写。
     */
    public static function callbackSign(string $mobile, string $customNumber, string $key): string
    {
        return md5($mobile . $customNumber . $key);
    }

    /**
     * @param array<string, string> $config
     */
    private function queryOrder(array $config, string $attemptNo, ?string $supplierOrderNo): RechargeResult
    {
        [$sent, $response] = $this->call($config, [
            'method' => 'orderstatus',
            'user' => $config['user'] ?? '',
            'custom_number' => $attemptNo,
        ]);
        $body = $this->decode($response['body']);
        if ($body !== null && $body['ResultCode'] === self::CODE_NOT_FOUND) {
            return RechargeResult::failed('上游订单不存在：' . $this->describe($body, $response), $supplierOrderNo, $sent, $response['body']);
        }
        $status = $body !== null && $body['ResultCode'] === self::CODE_OK && is_scalar($body['OrderStatus'] ?? null) ? (string) $body['OrderStatus'] : null;

        return match ($status) {
            self::STATUS_SUCCESS => RechargeResult::success($supplierOrderNo, null, $sent, $response['body']),
            self::STATUS_FAILED => RechargeResult::failed($this->message($body) ?: '上游充值失败', $supplierOrderNo, $sent, $response['body']),
            // 1000 充值中，以及 1111 调用验证失败（文档：不可处理失败）、表外的情况
            default => RechargeResult::processing($supplierOrderNo, $status === null ? '查单未拿到明确结果：' . $this->describe($body, $response) : null, $sent, $response['body']),
        };
    }

    /**
     * 发 GET 请求，签名只算 $signed，$optional 跟在后面不参与签名。
     *
     * @param array<string, string> $config
     * @param array<string, string> $signed
     * @param array<string, string> $optional
     * @return array{0: string, 1: array{status: int, body: string}} 实际发送的参数（不含 sign）、响应
     */
    private function call(array $config, array $signed, array $optional = [], float $timeout = 5.0): array
    {
        $params = $signed + $optional;
        $url = rtrim($config['api_url'] ?? '', '/') . self::PATH;
        $response = $this->http->get($url, $params + ['sign' => self::sign($signed, $config['key'] ?? '')], $timeout);

        return [(string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $response];
    }

    /**
     * ResultCode 统一转成字符串；.aspx 返回可能带 BOM，先去掉。
     *
     * @return null|array<string, mixed>
     */
    private function decode(string $raw): ?array
    {
        $body = json_decode(self::stripBom($raw), true);
        if (! is_array($body) || ! is_scalar($body['ResultCode'] ?? null)) {
            return null;
        }
        $body['ResultCode'] = (string) $body['ResultCode'];

        return $body;
    }

    /**
     * @param null|array<string, mixed> $body
     */
    private function message(?array $body): string
    {
        return is_scalar($body['ResultMessage'] ?? null) ? trim((string) $body['ResultMessage']) : '';
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
        $message = $this->message($body);

        return '[' . $body['ResultCode'] . '] ' . ($message === '' ? self::REJECT_CODES[$body['ResultCode']] ?? '' : $message);
    }

    private static function stripBom(string $raw): string
    {
        return str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
    }
}
