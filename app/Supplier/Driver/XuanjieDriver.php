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
 * 炫捷增值业务接口，文档见 docs/炫捷.md（最后是对接方式和结果判定表）。只接话费：下单、查单、余额、回调。
 *
 * - szOrderId 传提交单号 attempt_no，szPhoneNum 传手机号，nMoney 传整数面值，nSortType 按运营商（1 移动、2 联通、3 电信），
 *   nProductClass / nProductType 固定 1，常规话费不传 szProductId；上游没有自己的订单号；
 * - 全部接口表单 POST，带 szFormat=json；签名：按文档给的固定顺序拼 k=v&...，末尾拼 &szKey=，md5 小写；
 *   szTimeStamp 是北京时间 yyyy-MM-dd HH:mm:ss，晚于上游 1 分钟算过期；
 * - 下单只有 nRtn=0 算受理；上游有返回、nRtn 是别的（含 3003 订单号重复和文档写「未确认」的表外的码）一律判失败
 *   换下一家（2026-10-25 定）；网络异常、HTTP 出错、返回无法解析（没有拿到返回值）按接口异常处理，订单立即转异常人工处理；
 * - 查单 5012 成功、5013 失败、5011 处理中；5005 订单不存在只记录，订单状态不动（下单受理了就以下单为准）；
 *   5002 查询频繁、5003 / 5004、表外的码都不处理订单，按处理中；
 * - 回调按文档字段验签（szRtnMsg 不参与）并核对 szAgentId，nFlag 2 成功、3 失败、其他不处理订单，验签通过回 ok。
 * - 查余额 30 秒只能查一次，频繁时返回 5002（SupplierGateway 已把余额结果缓存 30 秒）。
 */
class XuanjieDriver implements SupplierDriverInterface
{
    private const PATH_SUBMIT = '/plat/api/old/submitorder';

    private const PATH_QUERY = '/plat/api/old/queryorder';

    private const PATH_BALANCE = '/plat/api/old/queryBalance';

    private const CODE_OK = '0';

    /** 查单、查余额太频繁（30 秒只能查一次） */
    private const CODE_FREQUENT = '5002';

    /** 下单返回码的文档说明：上游没给描述时用；下单除了 0 都按失败处理 */
    private const SUBMIT_CODES = [
        '1000' => '系统接口维护',
        '1001' => '时间格式错误或非表单请求',
        '1003' => '时间戳过期或初始化卡单上限',
        '1004' => '签名错误',
        '2001' => '号段错误',
        '2002' => '运营商错误',
        '2003' => '号码黑名单或充值次数上限',
        '2020' => 'IP未绑定',
        '2021' => '用户不存在',
        '1006' => '余额不足',
        '2030' => '产品没有配置或关闭',
        '3003' => '订单号重复',
    ];

    private const QUERY_PROCESSING = '5011';

    private const QUERY_SUCCESS = '5012';

    private const QUERY_FAILED = '5013';

    /** 查单时订单不存在：只记录，订单状态不动 */
    private const QUERY_NOT_FOUND = '5005';

    /** 查单返回码的文档说明：上游没给描述时用 */
    private const QUERY_CODES = [
        '5002' => '查询频繁',
        '5003' => '参数有误',
        '5004' => '签名错误',
        '5005' => '订单不存在',
        '5011' => '充值中',
        '5012' => '充值成功',
        '5013' => '充值失败',
    ];

    private const FLAG_SUCCESS = '2';

    private const FLAG_FAILED = '3';

    /** 上游 nSortType：1 移动、2 联通、3 电信 */
    private const SORT_TYPES = ['cmcc' => '1', 'cucc' => '2', 'ctcc' => '3'];

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'xuanjie';
    }

    public function name(): string
    {
        return '炫捷 API（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口地址', 'type' => 'text', 'required' => true, 'placeholder' => 'http://IP:端口'],
            ['key' => 'agent_id', 'label' => '客户ID（szAgentId）', 'type' => 'text', 'required' => true],
            ['key' => 'key', 'label' => '密钥（szKey）', 'type' => 'secret', 'required' => true],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        $sortType = self::SORT_TYPES[$request->operator] ?? null;
        if ($sortType === null) {
            return RechargeResult::failed('炫捷不支持该运营商：' . $request->operator, $request->supplierOrderNo);
        }
        $signed = [
            'szAgentId' => $request->config['agent_id'] ?? '',
            'szOrderId' => $request->attemptNo,
            'szPhoneNum' => $request->mobile,
            'nMoney' => (string) $request->faceValue,
            'nSortType' => $sortType,
            'nProductClass' => '1',
            'nProductType' => '1',
            'szTimeStamp' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'),
        ];
        [$sent, $response] = $this->call($request->config, self::PATH_SUBMIT, $signed, ['szNotifyUrl' => $request->config['notify_url'] ?? ''], 10.0);
        $body = $this->decode($response['body']);
        if ($body === null) {
            // HTTP 出错、返回无法解析：没有拿到返回值，不知道建没建单，转异常人工处理
            return RechargeResult::callFailed('下单没有拿到上游返回，转异常人工处理：' . $this->describe($body, $response), $sent, $response['body']);
        }
        if ($body['nRtn'] !== self::CODE_OK) {
            return RechargeResult::failed('上游拒单：' . $this->describe($body, $response), $request->supplierOrderNo, $sent, $response['body']);
        }

        return RechargeResult::processing($request->supplierOrderNo, null, $sent, $response['body']);
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, self::PATH_QUERY, [
            'szAgentId' => $request->config['agent_id'] ?? '',
            'szOrderId' => $request->attemptNo,
        ]);
        $body = $this->decode($response['body']);
        $code = $body['nRtn'] ?? null;
        if ($code === self::QUERY_NOT_FOUND) {
            return RechargeResult::processing($request->supplierOrderNo, '查单：上游订单不存在，只记录：' . $this->describe($body, $response), $sent, $response['body']);
        }
        $orderId = is_scalar($body['szOrderId'] ?? null) ? (string) $body['szOrderId'] : '';
        if ($orderId !== '' && strcasecmp($orderId, $request->attemptNo) !== 0) {
            return RechargeResult::processing($request->supplierOrderNo, '查单返回的订单号对不上：' . $orderId, $sent, $response['body']);
        }

        return match ($code) {
            self::QUERY_SUCCESS => RechargeResult::success($request->supplierOrderNo, null, $sent, $response['body']),
            self::QUERY_FAILED => RechargeResult::failed($this->message($body) ?: '上游充值失败', $request->supplierOrderNo, $sent, $response['body']),
            self::QUERY_PROCESSING => RechargeResult::processing($request->supplierOrderNo, null, $sent, $response['body']),
            // 5002 查询频繁、5003 参数有误、5004 签名错误、表外的码、没拿到返回：不处理订单
            default => RechargeResult::processing($request->supplierOrderNo, '查单未拿到明确结果：' . $this->describe($body, $response), $sent, $response['body']),
        };
    }

    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        $params = $request->getParsedBody();
        if (! is_array($params) || $params === []) {
            parse_str((string) $request->getBody(), $params);
        }
        $params = array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $params);
        $attemptNo = $params['szOrderId'] ?? '';
        $given = strtolower($params['szVerifyString'] ?? '');
        $key = $config['key'] ?? '';
        if ($attemptNo === '' || $given === '' || $key === '' || ! hash_equals(self::callbackSign($params, $key), $given)) {
            return new CallbackResult(false, 'fail');
        }
        if (($params['szAgentId'] ?? '') !== ($config['agent_id'] ?? '')) {
            return new CallbackResult(false, 'fail');
        }
        $raw = (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = match ($params['nFlag'] ?? '') {
            self::FLAG_SUCCESS => RechargeResult::success(null, null, null, $raw),
            self::FLAG_FAILED => RechargeResult::failed('上游充值失败', null, null, $raw),
            // 文档：其他 nFlag 未确认，不要处理订单
            default => RechargeResult::processing(null, '回调 nFlag 未确认：' . ($params['nFlag'] ?? ''), null, $raw),
        };

        return new CallbackResult(true, 'ok', $attemptNo, null, $result);
    }

    public function balance(array $config): ?string
    {
        [, $response] = $this->call($config, self::PATH_BALANCE, ['szAgentId' => $config['agent_id'] ?? '']);
        $body = $this->decode($response['body']);
        if ($body !== null && $body['nRtn'] === self::CODE_FREQUENT) {
            throw new RuntimeException('查询太频繁，30 秒后再刷新');
        }
        if ($body === null || $body['nRtn'] !== self::CODE_OK || ! is_scalar($body['fBalance'] ?? null)) {
            throw new RuntimeException('查询余额失败：' . mb_substr($response['body'], 0, 200));
        }

        return (string) $body['fBalance'];
    }

    /**
     * 请求签名：参数按传入顺序（即文档给的固定顺序）拼 k=v&...，末尾拼 &szKey=，md5 小写。
     *
     * @param array<string, string> $params
     */
    public static function sign(array $params, string $key): string
    {
        $pairs = [];
        foreach ($params + ['szKey' => $key] as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return md5(implode('&', $pairs));
    }

    /**
     * 回调签名：按文档顺序取 szAgentId、szOrderId、szPhoneNum、nDemo、fSalePrice、nFlag（szRtnMsg 不参与），值按收到的原样。
     *
     * @param array<string, string> $params
     */
    public static function callbackSign(array $params, string $key): string
    {
        $signed = [];
        foreach (['szAgentId', 'szOrderId', 'szPhoneNum', 'nDemo', 'fSalePrice', 'nFlag'] as $name) {
            $signed[$name] = $params[$name] ?? '';
        }

        return self::sign($signed, $key);
    }

    /**
     * 发表单 POST：$signed 按顺序参与签名，$extra 和 szFormat 跟在后面不参与签名。
     *
     * @param array<string, string> $config
     * @param array<string, string> $signed
     * @param array<string, string> $extra
     * @return array{0: string, 1: array{status: int, body: string}} 实际发送的参数（不含签名）、响应
     */
    private function call(array $config, string $path, array $signed, array $extra = [], float $timeout = 5.0): array
    {
        $params = $signed + $extra + ['szFormat' => 'json'];
        $url = rtrim($config['api_url'] ?? '', '/') . $path;
        $response = $this->http->postForm($url, $params + ['szVerifyString' => self::sign($signed, $config['key'] ?? '')], $timeout);

        return [(string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $response];
    }

    /**
     * nRtn 统一转成字符串。
     *
     * @return null|array<string, mixed>
     */
    private function decode(string $raw): ?array
    {
        $body = json_decode($raw, true);
        if (! is_array($body) || ! is_scalar($body['nRtn'] ?? null)) {
            return null;
        }
        $body['nRtn'] = (string) $body['nRtn'];

        return $body;
    }

    /**
     * @param null|array<string, mixed> $body
     */
    private function message(?array $body): string
    {
        return is_scalar($body['szRtnCode'] ?? null) ? trim((string) $body['szRtnCode']) : '';
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

        return '[' . $body['nRtn'] . '] ' . ($message === '' ? self::SUBMIT_CODES[$body['nRtn']] ?? self::QUERY_CODES[$body['nRtn']] ?? '' : $message);
    }
}
