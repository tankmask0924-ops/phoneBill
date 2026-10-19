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

namespace App\Service\Payment;

use App\Dao\MerchantOnlinePaymentDao;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantOnlinePayment;
use App\Network\HttpClient;
use App\Payment\AlipayClient;
use App\Service\AbstractService;
use App\Service\Merchant\MerchantBalanceService;
use App\Service\Setting\SettingService;
use App\Support\RedisLock;
use Hyperf\Database\Exception\QueryException;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * 支付宝扫码转账加款（docs/project.md 4.6.2）：.
 *
 * - 商户发起时生成支付单号和应付金额：输入金额加 0.01～0.99 的随机分，在所有未结束的单之间唯一（active_amount 唯一索引），
 *   二维码唤起支付宝转账页，预填收款账号、应付金额、备注（支付单号）；
 * - 认到账：查收款账户的账务明细（alipay.data.bill.accountlog.query），收入、金额和应付金额完全相等、入账时间在这单的
 *   核对期内、这条流水没入过账，就给这单入账。支付宝说明账务备注「不可依赖此字段进行对账」，所以备注不是必要条件，
 *   只在备注里写的是别的支付单号时不认；
 * - 入账：带条件把状态改成已到账、记下流水号（唯一索引，一条流水只能入一单），同一个事务里按应付金额给商户加款；
 * - 每分钟自动核对一次，商户点「我已付款」时立即核对（全局 5 秒最多一次）；二维码到期后再核对 10 分钟，还没到就过期，
 *   过期的单之后到账由后台查找到账记录确认入账。
 */
class AlipayTransferService extends AbstractService
{
    /** 系统配置里的设置项 */
    public const SETTING_KEY = 'payment.alipay_transfer';

    public const DEFAULT_EXPIRE_MINUTES = 15;

    public const DEFAULT_MIN_AMOUNT = '1.00';

    public const DEFAULT_MAX_AMOUNT = '50000.00';

    /** 二维码到期后继续自动核对的分钟数（支付宝入账可能晚一点） */
    public const GRACE_MINUTES = 10;

    /** 一个商户同时最多几笔待支付 */
    public const MAX_PENDING_PER_MERCHANT = 3;

    /** 支付单号前缀，备注里出现别的支付单号时不认 */
    private const ORDER_NO_PREFIX = 'AT';

    /** 入账时间允许比下单时间早这么多秒（两边时钟误差） */
    private const CLOCK_TOLERANCE = 60;

    /** 账务明细查询的时间范围最长 31 天 */
    private const MAX_QUERY_DAYS = 31;

    #[Inject]
    protected SettingService $settingService;

    #[Inject]
    protected MerchantOnlinePaymentDao $paymentDao;

    #[Inject]
    protected MerchantBalanceService $balanceService;

    #[Inject]
    protected HttpClient $http;

    #[Inject]
    protected RedisLock $lock;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 已保存的配置（不管有没有启用），没保存过返回 null。
     *
     * @return null|array{enabled: bool, app_id: string, private_key: string, alipay_public_key: string, account: string, account_name: string, expire_minutes: int, min_amount: string, max_amount: string}
     */
    public function config(): ?array
    {
        $config = $this->settingService->get(self::SETTING_KEY);
        if ($config === null) {
            return null;
        }

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'app_id' => (string) ($config['app_id'] ?? ''),
            'private_key' => (string) ($config['private_key'] ?? ''),
            'alipay_public_key' => (string) ($config['alipay_public_key'] ?? ''),
            'account' => (string) ($config['account'] ?? ''),
            'account_name' => (string) ($config['account_name'] ?? ''),
            'expire_minutes' => (int) ($config['expire_minutes'] ?? self::DEFAULT_EXPIRE_MINUTES),
            'min_amount' => (string) ($config['min_amount'] ?? self::DEFAULT_MIN_AMOUNT),
            'max_amount' => (string) ($config['max_amount'] ?? self::DEFAULT_MAX_AMOUNT),
        ];
    }

    /**
     * 启用了的配置，没启用返回 null。
     *
     * @return null|array<string, mixed> 同 config()
     */
    public function enabledConfig(): ?array
    {
        $config = $this->config();

        return $config !== null && $config['enabled'] ? $config : null;
    }

    /**
     * @param array<string, mixed> $config config() 的结果
     */
    public function client(array $config): AlipayClient
    {
        return new AlipayClient($this->http, $config['app_id'], $config['private_key'], $config['alipay_public_key']);
    }

    /**
     * 新建一笔待支付单，金额校验由调用方做。
     */
    public function create(int $merchantId, ?int $merchantUserId, string $amount): MerchantOnlinePayment
    {
        $config = $this->enabledConfig();
        if ($config === null) {
            throw new HttpException(422, '暂未开通支付宝在线加款');
        }
        if (bccomp($amount, $config['min_amount'], 2) < 0 || bccomp($amount, $config['max_amount'], 2) > 0) {
            throw new HttpException(422, "单笔金额需在 {$config['min_amount']} ~ {$config['max_amount']} 元之间");
        }
        if ($this->paymentDao->pendingCountForMerchant($merchantId) >= self::MAX_PENDING_PER_MERCHANT) {
            throw new HttpException(422, '待支付的单太多了，请先完成或取消之前的单');
        }
        $now = time();
        $expiresAt = $now + $config['expire_minutes'] * 60;
        for ($try = 1; $try <= 30; ++$try) {
            $payAmount = bcadd($amount, bcdiv((string) random_int(1, 99), '100', 2), 2);
            try {
                return $this->paymentDao->create([
                    'order_no' => self::ORDER_NO_PREFIX . date('YmdHis', $now) . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
                    'merchant_id' => $merchantId,
                    'merchant_user_id' => $merchantUserId,
                    'amount' => $amount,
                    'pay_amount' => $payAmount,
                    'active_amount' => $payAmount,
                    'receive_account' => $config['account'],
                    'receive_name' => $config['account_name'],
                    'status' => MerchantOnlinePayment::STATUS_PENDING,
                    'expires_at' => date('Y-m-d H:i:s', $expiresAt),
                    'checking_until' => date('Y-m-d H:i:s', $expiresAt + self::GRACE_MINUTES * 60),
                ]);
            } catch (QueryException $e) {
                // 应付金额被别的未结束的单占着，或者支付单号撞了：换一个重试
                if (str_contains($e->getMessage(), 'active_amount_unique') || str_contains($e->getMessage(), 'order_no_unique')) {
                    continue;
                }
                throw $e;
            }
        }
        throw new HttpException(422, '同金额的待支付单太多，请稍后再试或换个金额');
    }

    /**
     * 唤起支付宝转账页的链接（做成二维码给商户扫）：预填收款账号、金额、备注。
     */
    public static function transferUri(MerchantOnlinePayment $p): string
    {
        return 'alipays://platformapi/startapp?appId=20000200&actionType=toAccount&goBack=NO'
            . '&account=' . rawurlencode($p->receive_account)
            . '&amount=' . rawurlencode($p->pay_amount)
            . '&memo=' . rawurlencode($p->order_no);
    }

    /**
     * 商户点「我已付款」：立即核对一次，全局 5 秒内最多查一次支付宝。
     */
    public function checkNow(): void
    {
        if ($this->lock->once('alipay_transfer:check', 5)) {
            $this->reconcile();
        }
    }

    /**
     * 自动核对所有还在核对期内的待支付单，返回入账了几笔。没启用、没有待核对的单时不调支付宝。
     */
    public function reconcile(): int
    {
        $config = $this->enabledConfig();
        if ($config === null) {
            return 0;
        }
        $token = $this->lock->acquire('alipay_transfer:reconcile', 60);
        if ($token === null) {
            return 0;
        }
        try {
            $now = date('Y-m-d H:i:s');
            $orders = $this->paymentDao->checking($now);
            if ($orders->isEmpty()) {
                return 0;
            }
            $start = date('Y-m-d H:i:s', $orders->min(static fn (MerchantOnlinePayment $p) => $p->created_at->getTimestamp()) - self::CLOCK_TOLERANCE);
            // 结束时间不包含，往后一秒把这一秒的流水也查进来
            $entries = $this->entries($config, $start, date('Y-m-d H:i:s', time() + 1));
            $used = $this->usedLogIds($entries);
            $byAmount = [];
            foreach ($orders as $order) {
                $byAmount[$order->pay_amount] = $order;
            }
            $credited = 0;
            foreach ($entries as $entry) {
                $order = $byAmount[$entry['amount']] ?? null;
                if ($order === null || isset($used[$entry['account_log_id']]) || ! $this->matches($order, $entry, true)) {
                    continue;
                }
                if ($this->credit($order, $entry, MerchantOnlinePayment::CONFIRM_AUTO, null, null)) {
                    unset($byAmount[$entry['amount']]);
                    ++$credited;
                }
            }

            return $credited;
        } finally {
            $this->lock->release('alipay_transfer:reconcile', $token);
        }
    }

    /**
     * 过了核对期还没到账的改成已过期，已过期 / 已取消的过了核对期释放金额。返回过期了几笔。
     */
    public function expire(): int
    {
        $now = date('Y-m-d H:i:s');
        $expired = $this->paymentDao->expire($now);
        $this->paymentDao->releaseAmounts($now);

        return $expired;
    }

    /**
     * 后台查找这单可能的到账记录：从下单起（最长 31 天）金额相等、还没入过账的收入流水。备注是这单单号的排前面。
     *
     * @return list<array{account_log_id: string, amount: string, trans_at: null|string, alipay_order_no: null|string, payer_account: null|string, memo: null|string, memo_matches: bool}>
     */
    public function candidates(MerchantOnlinePayment $p): array
    {
        $config = $this->config();
        if ($config === null) {
            throw new HttpException(422, '还没有配置支付宝转账参数');
        }
        $start = $p->created_at->getTimestamp() - self::CLOCK_TOLERANCE;
        $end = min(time() + 1, $start + self::MAX_QUERY_DAYS * 86400);
        try {
            $entries = $this->entries($config, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end));
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        $used = $this->usedLogIds($entries);
        $result = [];
        foreach ($entries as $entry) {
            if ($entry['amount'] === $p->pay_amount && ! isset($used[$entry['account_log_id']]) && $this->matches($p, $entry, false)) {
                $result[] = $entry + ['memo_matches' => $entry['memo'] !== null && str_contains($entry['memo'], $p->order_no)];
            }
        }
        usort($result, static fn (array $a, array $b) => [$b['memo_matches'], $a['trans_at']] <=> [$a['memo_matches'], $b['trans_at']]);

        return $result;
    }

    /**
     * 入账：带条件改成已到账（同一条流水只能入一单），同一个事务里按应付金额给商户加款。被别人先处理了返回 false。
     *
     * @param null|array<string, mixed> $entry 匹配到的流水（entries() 的一条），后台没找到流水直接确认时为 null
     */
    public function credit(MerchantOnlinePayment $p, ?array $entry, string $confirmType, ?int $adminUserId, ?string $remark): bool
    {
        try {
            return Db::transaction(function () use ($p, $entry, $confirmType, $adminUserId, $remark) {
                $ok = $this->paymentDao->newQuery()
                    ->where('id', $p->id)
                    ->whereIn('status', [MerchantOnlinePayment::STATUS_PENDING, MerchantOnlinePayment::STATUS_EXPIRED, MerchantOnlinePayment::STATUS_CANCELLED])
                    ->update([
                        'status' => MerchantOnlinePayment::STATUS_PAID,
                        'active_amount' => null,
                        'paid_at' => date('Y-m-d H:i:s'),
                        'confirm_type' => $confirmType,
                        'account_log_id' => $entry['account_log_id'] ?? null,
                        'alipay_order_no' => $entry['alipay_order_no'] ?? null,
                        'payer_account' => $entry['payer_account'] ?? null,
                        'trans_memo' => $entry['memo'] ?? null,
                        'trans_at' => $entry['trans_at'] ?? null,
                        'remark' => $remark,
                        'confirmed_by' => $adminUserId,
                    ]) === 1;
                if (! $ok) {
                    return false;
                }
                $note = $confirmType === MerchantOnlinePayment::CONFIRM_AUTO ? '自动到账' : '后台确认入账';
                $log = $this->balanceService->change($p->merchant_id, MerchantBalanceLog::TYPE_RECHARGE, $p->pay_amount, null, "支付宝转账 {$p->order_no} {$note}", $adminUserId);
                $this->paymentDao->newQuery()->where('id', $p->id)->update(['balance_log_id' => $log->id]);
                $this->logger()->info('支付宝转账入账', ['order_no' => $p->order_no, 'merchant_id' => $p->merchant_id, 'amount' => $p->pay_amount, 'confirm_type' => $confirmType, 'account_log_id' => $entry['account_log_id'] ?? null]);

                return true;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'account_log_id_unique')) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * 查账务明细，整理成收入流水（入账时间升序）。支出、没有流水号、金额不对的丢掉。
     *
     * @param array<string, mixed> $config
     * @return list<array{account_log_id: string, amount: string, trans_at: null|string, alipay_order_no: null|string, payer_account: null|string, memo: null|string}>
     */
    private function entries(array $config, string $start, string $end): array
    {
        try {
            $items = $this->client($config)->accountLogs($start, $end);
        } catch (Throwable $e) {
            $this->logger()->error('查询支付宝账务明细失败', ['start' => $start, 'end' => $end, 'error' => $e->getMessage()]);
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
        $entries = [];
        foreach ($items as $item) {
            $entry = self::entry($item);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        usort($entries, static fn (array $a, array $b) => (string) $a['trans_at'] <=> (string) $b['trans_at']);

        return $entries;
    }

    /**
     * @param array<string, mixed> $item
     * @return null|array{account_log_id: string, amount: string, trans_at: null|string, alipay_order_no: null|string, payer_account: null|string, memo: null|string}
     */
    private static function entry(array $item): ?array
    {
        $id = is_scalar($item['account_log_id'] ?? null) ? (string) $item['account_log_id'] : '';
        $amount = $item['trans_amount'] ?? null;
        if ($id === '' || ! is_numeric($amount) || ($item['direction'] ?? '') === '支出') {
            return null;
        }
        $amount = bcadd((string) $amount, '0', 2);
        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }
        $transAt = is_string($item['trans_dt'] ?? null) && strtotime($item['trans_dt']) !== false ? date('Y-m-d H:i:s', strtotime($item['trans_dt'])) : null;
        $text = static fn (string $key, int $max) => is_scalar($item[$key] ?? null) && (string) $item[$key] !== '' ? mb_substr((string) $item[$key], 0, $max) : null;

        return [
            'account_log_id' => $id,
            'amount' => $amount,
            'trans_at' => $transAt,
            'alipay_order_no' => $text('alipay_order_no', 64),
            'payer_account' => $text('other_account', 128),
            'memo' => $text('trans_memo', 255),
        ];
    }

    /**
     * 流水能不能认给这单：入账时间不早于下单时间（自动核对时还不能晚于核对截止），备注里没写别的支付单号。
     *
     * @param array<string, mixed> $entry
     */
    private function matches(MerchantOnlinePayment $p, array $entry, bool $withinChecking): bool
    {
        if ($entry['trans_at'] === null) {
            return false;
        }
        $at = strtotime($entry['trans_at']);
        if ($at < $p->created_at->getTimestamp() - self::CLOCK_TOLERANCE) {
            return false;
        }
        if ($withinChecking && $at > $p->checking_until->getTimestamp()) {
            return false;
        }
        if ($entry['memo'] !== null && preg_match_all('/' . self::ORDER_NO_PREFIX . '\d{20}/', $entry['memo'], $m) > 0) {
            return in_array($p->order_no, $m[0], true);
        }

        return true;
    }

    /**
     * 这批流水里已经入过账的流水号。
     *
     * @param list<array<string, mixed>> $entries
     * @return array<string, true>
     */
    private function usedLogIds(array $entries): array
    {
        $ids = array_column($entries, 'account_log_id');
        if ($ids === []) {
            return [];
        }

        return array_fill_keys($this->paymentDao->newQuery()->whereIn('account_log_id', $ids)->pluck('account_log_id')->all(), true);
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('payment');
    }
}
