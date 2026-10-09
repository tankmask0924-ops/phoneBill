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

/**
 * 中控话费接口，文档见 docs/中控API接口.md（下单、查单、回调、余额，只接话费，不接卡密）。
 *
 * - inOrderNumber 传提交单号 attempt_no，agentProductId 传供应商商品编码，num 固定 1；
 * - 签名：除 sign 外参数按名升序拼 k=v&...，末尾拼 &key=秘钥，整串 md5；
 * - 文档没给各接口路径、时间戳格式前后矛盾，所以接口地址分开填、时间戳格式做成可选项；
 * - 文档没说清下单 code=500 时是否建了单、查不到订单时返回什么，所以下单只有 code 为 0 / 1 才算受理，
 *   其余一律处理中，结果只认查单和验签通过的回调。
 */
class ZhongkongDriver implements SupplierDriverInterface
{
    private const CODE_OK = '0';

    /** 商家订单号已经存在：之前已经提交过，绝不能当失败 */
    private const CODE_DUPLICATE = '1';

    private const STATUS_SUCCESS = 3;

    private const STATUS_FAILED = 4;

    /** 回调里为空时不参与签名的字段 */
    private const CALLBACK_OPTIONAL_FIELDS = ['param1', 'param2'];

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'zhongkong';
    }

    public function name(): string
    {
        return '中控 API（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'recharge_url', 'label' => '下单接口地址', 'type' => 'text', 'required' => true, 'placeholder' => 'https://上游域名/下单路径'],
            ['key' => 'query_url', 'label' => '查单接口地址', 'type' => 'text', 'required' => true, 'placeholder' => 'https://上游域名/查单路径'],
            ['key' => 'balance_url', 'label' => '余额接口地址', 'type' => 'text', 'required' => false, 'placeholder' => '不填则不查余额'],
            ['key' => 'appid', 'label' => '商家ID（appid）', 'type' => 'text', 'required' => true],
            ['key' => 'secret', 'label' => '秘钥', 'type' => 'secret', 'required' => true],
            [
                'key' => 'timestamp_format',
                'label' => '时间戳格式',
                'type' => 'select',
                'required' => true,
                'options' => [
                    ['value' => 'datetime', 'label' => '年月日时分秒（20261009120000）'],
                    ['value' => 'unix', 'label' => '秒级时间戳（1791518400）'],
                ],
            ],
            ['key' => 'notify_url', 'label' => '回调地址', 'type' => 'text', 'required' => true, 'placeholder' => 'https://我方域名/notify/supplier/供应商编码'],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, $request->config['recharge_url'] ?? '', [
            'inOrderNumber' => $request->attemptNo,
            'agentProductId' => $request->externalCode,
            'phone' => $request->mobile,
            'notifyUrl' => $request->config['notify_url'] ?? '',
            'num' => '1',
        ], 10.0);
        $body = $this->decode($response['body']);
        $code = $body['code'] ?? null;
        if ($code === self::CODE_OK) {
            return RechargeResult::processing($request->supplierOrderNo, null, $sent, $response['body']);
        }
        if ($code === self::CODE_DUPLICATE) {
            return RechargeResult::processing($request->supplierOrderNo, '上游订单号已存在（之前已提交过），等待结果', $sent, $response['body']);
        }

        // 包括 code=500：文档没说失败时一定没建单，判失败可能导致退款后上游又充上，只能等查单确认
        return RechargeResult::processing($request->supplierOrderNo, '下单未确认受理，等待查单：' . $this->describe($body, $response), $sent, $response['body']);
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, $request->config['query_url'] ?? '', ['inOrderNumber' => $request->attemptNo]);
        $body = $this->decode($response['body']);
        if ($body === null || $body['code'] !== self::CODE_OK || ! is_numeric($body['status'] ?? null)) {
            return RechargeResult::processing($request->supplierOrderNo, '查单未拿到明确结果：' . $this->describe($body, $response), $sent, $response['body']);
        }

        return $this->statusResult((int) $body['status'], $request->supplierOrderNo, $sent, $response['body']);
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
        $params = array_map(static fn ($v) => self::stringify($v), $params);
        $attemptNo = $params['thirdOrderId'] ?? '';
        if ($attemptNo === '' || ! $this->callbackSignValid($params, $config['secret'] ?? '') || ! is_numeric($params['status'] ?? null)) {
            return new CallbackResult(false, 'fail');
        }

        return new CallbackResult(true, 'success', $attemptNo, null, $this->statusResult((int) $params['status'], null, null, $raw));
    }

    public function balance(array $config): ?string
    {
        if (($config['balance_url'] ?? '') === '') {
            return null;
        }
        [, $response] = $this->call($config, $config['balance_url'], []);
        $body = $this->decode($response['body']);
        if ($body === null || $body['code'] !== self::CODE_OK || ! is_scalar($body['debtAmount'] ?? null)) {
            throw new RuntimeException('查询余额失败：' . mb_substr($response['body'], 0, 200));
        }

        return (string) $body['debtAmount'];
    }

    /**
     * 按上游文档「签名说明」计算签名，请求签名和回调验签共用。
     *
     * @param array<string, string> $params
     */
    public static function sign(array $params, string $secret): string
    {
        unset($params['sign']);
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        $pairs[] = 'key=' . $secret;

        return md5(implode('&', $pairs));
    }

    /**
     * 文档只说了 param1 / param2 为空时不参与签名，没说其他空字段（比如话费的 cards）怎么算，
     * 两种算法有一种对上就算通过；两种都要秘钥，不影响防伪造。
     *
     * @param array<string, string> $params
     */
    private function callbackSignValid(array $params, string $secret): bool
    {
        $given = strtolower($params['sign'] ?? '');
        if ($given === '' || $secret === '') {
            return false;
        }
        $documented = array_filter($params, static fn ($v, $k) => $v !== '' || ! in_array($k, self::CALLBACK_OPTIONAL_FIELDS, true), ARRAY_FILTER_USE_BOTH);
        $nonEmpty = array_filter($params, static fn ($v) => $v !== '');

        return hash_equals(self::sign($documented, $secret), $given) || hash_equals(self::sign($nonEmpty, $secret), $given);
    }

    /**
     * 查单和回调的状态：3 成功、4 失败，其他都是充值中。
     */
    private function statusResult(int $status, ?string $supplierOrderNo, ?string $request, string $raw): RechargeResult
    {
        return match ($status) {
            self::STATUS_SUCCESS => RechargeResult::success($supplierOrderNo, null, $request, $raw),
            self::STATUS_FAILED => RechargeResult::failed('上游充值失败', $supplierOrderNo, $request, $raw),
            default => RechargeResult::processing($supplierOrderNo, null, $request, $raw),
        };
    }

    /**
     * 带上 appid、时间戳和签名发 JSON 请求。
     *
     * @param array<string, string> $config
     * @param array<string, string> $business
     * @return array{0: string, 1: array{status: int, body: string}} 实际发送的参数（不含 sign）、响应
     */
    private function call(array $config, string $url, array $business, float $timeout = 5.0): array
    {
        $params = $business + [
            'appid' => $config['appid'] ?? '',
            'timestamp' => ($config['timestamp_format'] ?? 'datetime') === 'unix' ? (string) time() : date('YmdHis'),
        ];
        $response = $this->http->postJson($url, $params + ['sign' => self::sign($params, $config['secret'] ?? '')], $timeout);

        return [(string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $response];
    }

    /**
     * code 统一转成字符串（文档里是字符串，防止上游返回数字）。
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
        $message = $body['msg'] ?? $body['message'] ?? '';

        return '[' . $body['code'] . '] ' . (is_scalar($message) ? (string) $message : '');
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return $value === null ? '' : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
