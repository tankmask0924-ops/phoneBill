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
 * YB 接口，文档见 docs/YB接口文档-2026.md（最后是对接方式和结果判定表）。只接话费：下单、查单、余额、回调；不接撤单。
 *
 * - 所有接口同一个地址，表单 POST，按 Method 区分；OrderNo 传提交单号 attempt_no，ProductID 传供应商商品编码，
 *   Account 传手机号，BuyNum 固定 1，ChargeWay / Game / Zone / Server 传空串，CustomerSalePrice 固定传 0（不让上游校验进价）；
 * - 签名：参数按名 ASCII 升序拼 k=v&...（值不做 URL 编码，空值也参与），末尾拼 &key=，按 GB2312 编码后 md5 小写；
 * - 下单 Code=success 按 iOrderStatus 判断：1 成功、-1 失败、其他处理中；Code=fail 判失败换下一家，
 *   但 Msg 是「订单重复提交」时上游已有这笔单，按处理中等回调 / 查单；
 *   网络异常、HTTP 出错、返回无法解析（没有拿到返回值）按接口异常处理，订单立即转异常人工处理；
 * - 查单 Status 1 成功、-1 失败、其他处理中；返回的 OrderNo 必须是提交单号；
 *   「无该订单信息」按文档：提交不满 5 分钟只记录，满 5 分钟仍查不到判失败（上游没收到这笔单）；
 * - 回调是 JSON，签名 md5(OrderNo=&OrderStatus=&key=)，OrderStatus 1 / -1 才处理订单，验签通过回 SUCCESS。
 */
class YbDriver implements SupplierDriverInterface
{
    private const METHOD_BUY = 'Product.ApiBuyYB';

    private const METHOD_QUERY = 'SaleOrder.OrderInfoYB';

    private const METHOD_BALANCE = 'Customer.CustomerInfo';

    private const CODE_OK = 'success';

    /** 下单返回这个信息时上游已有这笔单，不能判失败 */
    private const MSG_DUPLICATE = '订单重复提交';

    /** 查单时上游查不到：提交满 NOT_FOUND_FAIL_AFTER 秒仍查不到判失败，之前只记录 */
    private const MSG_NOT_FOUND = '无该订单信息';

    /** 文档：返回无该订单信息时过 5 分钟再查，还是这样就可以作失败处理 */
    private const NOT_FOUND_FAIL_AFTER = 300;

    /** 不让上游按进价拦单 */
    private const CUSTOMER_SALE_PRICE = '0';

    private const STATUS_SUCCESS = '1';

    private const STATUS_FAILED = '-1';

    /** 签名串按 GB2312 编码后再 md5；CP936（GBK）是它的超集，GB2312 范围内编码一致 */
    private const SIGN_ENCODING = 'CP936';

    #[Inject]
    protected HttpClient $http;

    public function code(): string
    {
        return 'yb';
    }

    public function name(): string
    {
        return 'YB（话费）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口地址', 'type' => 'text', 'required' => true, 'placeholder' => '接口账户信息里的接口地址（完整 URL）'],
            ['key' => 'customer_id', 'label' => '客户编号', 'type' => 'text', 'required' => true],
            ['key' => 'key', 'label' => '密钥', 'type' => 'secret', 'required' => true],
            ['key' => 'customer_ip', 'label' => '本站 IP', 'type' => 'text', 'required' => true, 'placeholder' => '本站服务器出口 IP，下单时作为 CustomerIp 传给上游'],
        ];
    }

    public function recharge(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, self::METHOD_BUY, [
            'ProductID' => $request->externalCode,
            'BuyNum' => '1',
            'Account' => $request->mobile,
            'ChargeWay' => '',
            'Game' => '',
            'Zone' => '',
            'Server' => '',
            'OrderNo' => $request->attemptNo,
            'ReturnUrl' => $request->config['notify_url'] ?? '',
            'CustomerIp' => $request->config['customer_ip'] ?? '',
            'CustomerSalePrice' => self::CUSTOMER_SALE_PRICE,
        ], 10.0);
        $body = $this->decode($response['body']);
        if ($body === null) {
            // HTTP 出错、返回无法解析：没有拿到返回值，不知道建没建单，转异常人工处理
            return RechargeResult::callFailed('下单没有拿到上游返回，转异常人工处理：' . $this->describe($body, $response), $sent, $response['body']);
        }
        if ($body['code'] !== self::CODE_OK) {
            if (str_contains($body['msg'], self::MSG_DUPLICATE)) {
                return RechargeResult::processing($request->supplierOrderNo, '上游返回订单重复提交，按已受理等回调 / 查单：' . $this->describe($body, $response), $sent, $response['body']);
            }

            return RechargeResult::failed('上游拒单：' . $this->describe($body, $response), $request->supplierOrderNo, $sent, $response['body']);
        }
        $data = $body['data'];
        $orderNo = $this->scalar($data, 'SaleOrderNo') ?? $request->supplierOrderNo;

        return match ($this->scalar($data, 'iOrderStatus')) {
            self::STATUS_SUCCESS => RechargeResult::success($orderNo, null, $sent, $response['body']),
            self::STATUS_FAILED => RechargeResult::failed('上游充值失败：' . $this->describe($body, $response), $orderNo, $sent, $response['body']),
            default => RechargeResult::processing($orderNo, null, $sent, $response['body']),
        };
    }

    public function query(RechargeRequest $request): RechargeResult
    {
        [$sent, $response] = $this->call($request->config, self::METHOD_QUERY, ['OrderNo' => $request->attemptNo]);
        $body = $this->decode($response['body']);
        if ($body !== null && $body['code'] !== self::CODE_OK && str_contains($body['msg'], self::MSG_NOT_FOUND)) {
            // 不知道提交时间时不判失败
            if ($request->submittedAt !== null && time() - $request->submittedAt >= self::NOT_FOUND_FAIL_AFTER) {
                return RechargeResult::failed('提交 5 分钟后上游仍无该订单信息，按失败处理', $request->supplierOrderNo, $sent, $response['body']);
            }

            return RechargeResult::processing($request->supplierOrderNo, '查单：上游无该订单，提交未满 5 分钟，只记录：' . $this->describe($body, $response), $sent, $response['body']);
        }
        $data = $body !== null && $body['code'] === self::CODE_OK ? $body['data'] : [];
        $status = $this->scalar($data, 'Status');
        if ($status === null) {
            return RechargeResult::processing($request->supplierOrderNo, '查单未拿到明确结果：' . $this->describe($body, $response), $sent, $response['body']);
        }
        // OrderNo 是我们的提交单号（已和上游确认），对不上不采用
        $returned = $this->scalar($data, 'OrderNo');
        if ($returned !== null && $returned !== $request->attemptNo) {
            return RechargeResult::processing($request->supplierOrderNo, '查单返回的订单号对不上：' . $returned, $sent, $response['body']);
        }
        $orderNo = $this->scalar($data, 'Temp1') ?? $request->supplierOrderNo;
        $note = $this->scalar($data, 'ProcessNote');

        return match ($status) {
            self::STATUS_SUCCESS => RechargeResult::success($orderNo, null, $sent, $response['body']),
            self::STATUS_FAILED => RechargeResult::failed($note ?? '上游充值失败', $orderNo, $sent, $response['body']),
            default => RechargeResult::processing($orderNo, null, $sent, $response['body']),
        };
    }

    public function parseCallback(ServerRequestInterface $request, array $config): CallbackResult
    {
        // 文档写的是 JSON，表单也收
        $raw = (string) $request->getBody();
        $params = json_decode($raw, true);
        if (! is_array($params)) {
            $params = $request->getParsedBody();
        }
        if (! is_array($params) || $params === []) {
            parse_str($raw, $params);
        }
        $params = array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $params);
        $attemptNo = $params['OrderNo'] ?? '';
        $status = $params['OrderStatus'] ?? '';
        $given = strtolower($params['Sign'] ?? '');
        $key = $config['key'] ?? '';
        if ($attemptNo === '' || $given === '' || $key === '' || ! hash_equals(self::sign(['OrderNo' => $attemptNo, 'OrderStatus' => $status], $key), $given)) {
            return new CallbackResult(false, 'fail');
        }
        unset($params['Sign']);
        $raw = (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $orderNo = ($params['Temp1'] ?? '') === '' ? null : $params['Temp1'];
        $message = trim($params['OrderMessage'] ?? '');
        $result = match ($status) {
            self::STATUS_SUCCESS => RechargeResult::success($orderNo, null, null, $raw),
            self::STATUS_FAILED => RechargeResult::failed($message === '' ? '上游充值失败' : $message, $orderNo, null, $raw),
            default => RechargeResult::processing($orderNo, '回调 OrderStatus 未确认：' . $status, null, $raw),
        };

        return new CallbackResult(true, 'SUCCESS', $attemptNo, $orderNo, $result);
    }

    public function balance(array $config): ?string
    {
        [, $response] = $this->call($config, self::METHOD_BALANCE, []);
        $body = $this->decode($response['body']);
        $balance = $body !== null && $body['code'] === self::CODE_OK ? $this->scalar($body['data'], 'Balance') : null;
        if ($balance === null || ! is_numeric($balance)) {
            throw new RuntimeException('查询余额失败：' . mb_substr($response['body'], 0, 200));
        }

        return $balance;
    }

    /**
     * 按文档「签名验证」计算签名：参数按名 ASCII 升序拼 k=v&...（值不做 URL 编码，空值也参与），
     * 末尾拼 &key=密钥，整串按 GB2312 编码后 md5 小写。
     *
     * @param array<string, string> $params
     */
    public static function sign(array $params, string $key): string
    {
        unset($params['Sign']);
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $pairs[] = 'key=' . $key;

        return md5((string) mb_convert_encoding(implode('&', $pairs), self::SIGN_ENCODING, 'UTF-8'));
    }

    /**
     * 带上 Method、CustomerID 和签名发表单 POST。
     *
     * @param array<string, string> $config
     * @param array<string, string> $business
     * @return array{0: string, 1: array{status: int, body: string}} 实际发送的参数（不含 Sign）、响应
     */
    private function call(array $config, string $method, array $business, float $timeout = 5.0): array
    {
        $params = ['Method' => $method, 'CustomerID' => $config['customer_id'] ?? ''] + $business;
        $response = $this->http->postForm(trim($config['api_url'] ?? ''), $params + ['Sign' => self::sign($params, $config['key'] ?? '')], $timeout);

        return [(string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $response];
    }

    /**
     * 取 Status.Code（转小写）、Status.Msg 和 Data 的第一条；没有 Code 算没拿到返回值。
     * 上游万一按 GBK 返回中文，转成 UTF-8 再解析。
     *
     * @return null|array{code: string, msg: string, data: array<string, mixed>}
     */
    private function decode(string $raw): ?array
    {
        if ($raw !== '' && ! mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', self::SIGN_ENCODING);
        }
        $body = json_decode($raw, true);
        $status = is_array($body) && is_array($body['Status'] ?? null) ? $body['Status'] : null;
        if ($status === null || ! is_scalar($status['Code'] ?? null) || (string) $status['Code'] === '') {
            return null;
        }
        $data = $body['Data'] ?? null;
        if (is_array($data) && array_is_list($data)) {
            $data = $data[0] ?? null;
        }

        return [
            'code' => strtolower(trim((string) $status['Code'])),
            'msg' => is_scalar($status['Msg'] ?? null) ? trim((string) $status['Msg']) : '',
            'data' => is_array($data) ? $data : [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function scalar(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param null|array{code: string, msg: string, data: array<string, mixed>} $body
     * @param array{status: int, body: string} $response
     */
    private function describe(?array $body, array $response): string
    {
        if ($body === null) {
            return 'HTTP ' . $response['status'] . ' ' . mb_substr($response['body'], 0, 100);
        }

        return '[' . $body['code'] . '] ' . $body['msg'];
    }
}
