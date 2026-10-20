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

use App\Dao\AlipayAccountDao;
use App\Dao\MerchantDao;
use App\Dao\MerchantOnlinePaymentDao;
use App\Model\AlipayAccount;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantOnlinePayment;
use App\Network\HttpClient;
use App\Payment\AlipayClient;
use App\Security\Encrypter;
use App\Service\AbstractService;
use App\Service\Merchant\MerchantBalanceService;
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
 * - 收款账户可以有多个（alipay_accounts），每个商户指定用一个（merchants.alipay_account_id），没指定或账户停用就不能用；
 * - 商户发起时生成支付单号和应付金额：输入金额加 0.01～0.99 的随机分，在同一个收款账户的未结束单之间唯一
 *   （(alipay_account_id, active_amount) 唯一索引），二维码唤起支付宝转账页，预填收款账号、应付金额、备注（支付单号）；
 * - 认到账：按收款账户分别查账务明细（alipay.data.bill.accountlog.query），收入、金额和应付金额完全相等、入账时间在这单的
 *   核对期内、这条流水没入过账，就给这单入账。支付宝说明账务备注「不可依赖此字段进行对账」，所以备注不是必要条件，
 *   只在备注里写的是别的支付单号时不认；
 * - 入账：带条件把状态改成已到账、记下流水号（唯一索引，一条流水只能入一单），同一个事务里按应付金额给商户加款；
 * - 每分钟自动核对一次，商户点「我已付款」时立即核对这个账户（每个账户 5 秒最多一次）；二维码到期后再核对 10 分钟，
 *   还没到就过期，过期的单之后到账由后台查找到账记录确认入账。账户停用后已发起的单照常核对。
 */
class AlipayTransferService extends AbstractService
{
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
    protected AlipayAccountDao $accountDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantOnlinePaymentDao $paymentDao;

    #[Inject]
    protected MerchantBalanceService $balanceService;

    #[Inject]
    protected Encrypter $encrypter;

    #[Inject]
    protected HttpClient $http;

    #[Inject]
    protected RedisLock $lock;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 商户能用的收款账户：指定了、并且启用的。
     */
    public function merchantAccount(int $merchantId): ?AlipayAccount
    {
        $accountId = $this->merchantDao->alipayAccountId($merchantId);
        $account = $accountId === null ? null : $this->accountDao->find($accountId);

        return $account !== null && $account->status === AlipayAccount::STATUS_ACTIVE ? $account : null;
    }

    public function client(AlipayAccount $account): AlipayClient
    {
        return new AlipayClient($this->http, $account->app_id, $this->encrypter->decrypt($account->private_key), $account->alipay_public_key);
    }

    /**
     * 新建一笔待支付单，金额格式校验由调用方做。
     */
    public function create(int $merchantId, ?int $merchantUserId, string $amount): MerchantOnlinePayment
    {
        $account = $this->merchantAccount($merchantId);
        if ($account === null) {
            throw new HttpException(422, '暂未开通支付宝在线加款');
        }
        if (bccomp($amount, $account->min_amount, 2) < 0 || bccomp($amount, $account->max_amount, 2) > 0) {
            throw new HttpException(422, "单笔金额需在 {$account->min_amount} ~ {$account->max_amount} 元之间");
        }
        if ($this->paymentDao->pendingCountForMerchant($merchantId) >= self::MAX_PENDING_PER_MERCHANT) {
            throw new HttpException(422, '待支付的单太多了，请先完成或取消之前的单');
        }
        $now = time();
        $expiresAt = $now + $account->expire_minutes * 60;
        for ($try = 1; $try <= 30; ++$try) {
            $payAmount = bcadd($amount, bcdiv((string) random_int(1, 99), '100', 2), 2);
            try {
                return $this->paymentDao->create([
                    'order_no' => self::ORDER_NO_PREFIX . date('YmdHis', $now) . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
                    'merchant_id' => $merchantId,
                    'merchant_user_id' => $merchantUserId,
                    'alipay_account_id' => $account->id,
                    'amount' => $amount,
                    'pay_amount' => $payAmount,
                    'active_amount' => $payAmount,
                    'receive_account' => $account->account,
                    'receive_name' => $account->account_name,
                    'status' => MerchantOnlinePayment::STATUS_PENDING,
                    'expires_at' => date('Y-m-d H:i:s', $expiresAt),
                    'checking_until' => date('Y-m-d H:i:s', $expiresAt + self::GRACE_MINUTES * 60),
                ]);
            } catch (QueryException $e) {
                // 应付金额被这个账户别的未结束的单占着，或者支付单号撞了：换一个重试
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
     * 商户点「我已付款」：立即核对这单的收款账户，每个账户 5 秒内最多查一次支付宝。
     */
    public function checkNow(MerchantOnlinePayment $p): void
    {
        if ($p->alipay_account_id !== null && $this->lock->once('alipay_transfer:check:' . $p->alipay_account_id, 5)) {
            $this->reconcile($p->alipay_account_id);
        }
    }

    /**
     * 自动核对还在核对期内的待支付单（按收款账户分别查账务明细），返回入账了几笔。没有待核对的单时不调支付宝；
     * 一个账户查询失败只记日志，不影响其他账户。
     *
     * @param null|int $accountId 只核对这个账户，null 表示全部
     */
    public function reconcile(?int $accountId = null): int
    {
        $lockKey = 'alipay_transfer:reconcile:' . ($accountId ?? 'all');
        $token = $this->lock->acquire($lockKey, 60);
        if ($token === null) {
            return 0;
        }
        try {
            $orders = $this->paymentDao->checking(date('Y-m-d H:i:s'))
                ->filter(static fn (MerchantOnlinePayment $p) => $p->alipay_account_id !== null && ($accountId === null || $p->alipay_account_id === $accountId));
            $credited = 0;
            foreach ($orders->groupBy('alipay_account_id') as $id => $group) {
                $account = $this->accountDao->find((int) $id);
                if ($account === null) {
                    continue;
                }
                try {
                    $credited += $this->reconcileAccount($account, array_values($group->all()));
                } catch (RuntimeException) {
                    // 已记日志（entries()），下次再查
                }
            }

            return $credited;
        } finally {
            $this->lock->release($lockKey, $token);
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
     * 后台查找这单可能的到账记录：在这单的收款账户里，从下单起（最长 31 天）金额相等、还没入过账的收入流水。
     * 备注是这单单号的排前面。
     *
     * @return list<array{account_log_id: string, amount: string, trans_at: null|string, alipay_order_no: null|string, payer_account: null|string, memo: null|string, memo_matches: bool}>
     */
    public function candidates(MerchantOnlinePayment $p): array
    {
        $account = $p->alipay_account_id === null ? null : $this->accountDao->find($p->alipay_account_id);
        if ($account === null) {
            throw new HttpException(422, '这笔单的收款账户不存在');
        }
        $start = $p->created_at->getTimestamp() - self::CLOCK_TOLERANCE;
        $end = min(time() + 1, $start + self::MAX_QUERY_DAYS * 86400);
        try {
            $entries = $this->entries($account, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end));
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
     * 核对一个收款账户的待支付单。
     *
     * @param list<MerchantOnlinePayment> $orders
     */
    private function reconcileAccount(AlipayAccount $account, array $orders): int
    {
        $start = date('Y-m-d H:i:s', min(array_map(static fn (MerchantOnlinePayment $p) => $p->created_at->getTimestamp(), $orders)) - self::CLOCK_TOLERANCE);
        // 结束时间不包含，往后一秒把这一秒的流水也查进来
        $entries = $this->entries($account, $start, date('Y-m-d H:i:s', time() + 1));
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
    }

    /**
     * 查账务明细，整理成收入流水（入账时间升序）。支出、没有流水号、金额不对的丢掉。
     *
     * @return list<array{account_log_id: string, amount: string, trans_at: null|string, alipay_order_no: null|string, payer_account: null|string, memo: null|string}>
     */
    private function entries(AlipayAccount $account, string $start, string $end): array
    {
        try {
            $items = $this->client($account)->accountLogs($start, $end);
        } catch (Throwable $e) {
            $this->logger()->error('查询支付宝账务明细失败', ['alipay_account_id' => $account->id, 'start' => $start, 'end' => $end, 'error' => $e->getMessage()]);
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
