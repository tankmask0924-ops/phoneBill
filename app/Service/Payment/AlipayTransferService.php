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
 * 支付宝转账加款（docs/project.md 4.6.2）：.
 *
 * - 收款账户可以有多个（alipay_accounts），每个商户指定用一个（merchants.alipay_account_id），没指定或账户停用就不能用；
 *   账户上传一张固定收款码，商户扫码转任意金额；
 * - 商户转完账填支付宝订单号和金额，点「已转账」：按订单号查这个收款账户的账务明细（alipay.data.bill.accountlog.query），
 *   找到这笔订单的收入流水、金额和填的完全相等、这条流水没入过账，就入账；查不到先记成未到账，商户可以稍后重新查询，
 *   后台也能查找到账记录确认入账。没有定时任务，只在商户提交、重新查询时查支付宝；
 * - 一个支付宝订单号只能提交一次（唯一索引）：同一个商户没到账的单再提交就是改金额重新查，别的商户提交过的不能再提交；
 * - 入账：带条件把状态改成已到账、记下流水号（唯一索引，一条流水只能入一单），同一个事务里给商户加款。
 */
class AlipayTransferService extends AbstractService
{
    public const DEFAULT_MIN_AMOUNT = '1.00';

    public const DEFAULT_MAX_AMOUNT = '50000.00';

    /** 一个商户同时最多几笔未到账 */
    public const MAX_PENDING_PER_MERCHANT = 5;

    /** 同一个商户两次查询支付宝的最短间隔（秒） */
    public const CHECK_INTERVAL = 3;

    /** 支付单号前缀 */
    private const ORDER_NO_PREFIX = 'AT';

    /** 入账时间允许比下单时间早这么多秒（两边时钟误差），只用于改版前的单按金额找到账记录 */
    private const CLOCK_TOLERANCE = 60;

    /** 账务明细查询的时间范围最长 31 天，按订单号往前查 30 天 */
    private const MAX_QUERY_DAYS = 31;

    private const LOOKBACK_DAYS = 30;

    private const MESSAGE_NOT_FOUND = '暂未查到这笔转账：请确认填的是支付宝账单里这笔转账的「订单号」、转给的是平台的收款码；支付宝入账可能有几分钟延迟，可以稍后重新查询';

    private const MESSAGE_AMOUNT = '到账金额和填写的金额不一致，请核对金额后重新提交';

    private const MESSAGE_USED = '这笔转账已经入过账了';

    private const MESSAGE_FAILED = '查询支付宝失败，请稍后重新查询';

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
     * 商户提交一笔转账（支付宝订单号 + 金额）并立即查询到账，订单号、金额格式校验由调用方做。
     * 同一个商户没到账的单再提交同一个订单号，就是改金额重新查。
     */
    public function submit(int $merchantId, ?int $merchantUserId, string $alipayOrderNo, string $amount): MerchantOnlinePayment
    {
        $account = $this->merchantAccount($merchantId);
        if ($account === null) {
            throw new HttpException(422, '暂未开通支付宝转账加款');
        }
        if (bccomp($amount, $account->min_amount, 2) < 0 || bccomp($amount, $account->max_amount, 2) > 0) {
            throw new HttpException(422, "单笔金额需在 {$account->min_amount} ~ {$account->max_amount} 元之间");
        }
        $attrs = [
            'merchant_user_id' => $merchantUserId,
            'alipay_account_id' => $account->id,
            'amount' => $amount,
            'pay_amount' => $amount,
            'receive_account' => $account->account,
            'receive_name' => $account->account_name,
        ];
        $existing = $this->paymentDao->findByAlipayOrderNo($alipayOrderNo);
        if ($existing !== null) {
            $p = $this->reopen($existing, $merchantId, $attrs);
        } else {
            if ($this->paymentDao->pendingCountForMerchant($merchantId) >= self::MAX_PENDING_PER_MERCHANT) {
                throw new HttpException(422, '未到账的单太多了，请先重新查询或取消之前的单');
            }
            $this->throttle($merchantId);
            $p = $this->createPending($merchantId, $alipayOrderNo, $attrs);
        }

        return $this->check($p, false);
    }

    /**
     * 查询这笔未到账的单：到账就入账，没到账把原因记在 check_message 里。返回最新的单。
     *
     * @param bool $throttle 商户点「重新查询」时限频；提交时已经限过了
     */
    public function check(MerchantOnlinePayment $p, bool $throttle = true): MerchantOnlinePayment
    {
        if ($p->status !== MerchantOnlinePayment::STATUS_PENDING || $p->alipay_order_no === null) {
            return $p;
        }
        if ($throttle) {
            $this->throttle($p->merchant_id);
        }
        $message = $this->tryCredit($p);
        if ($message !== null) {
            $this->paymentDao->newQuery()
                ->where('id', $p->id)
                ->where('status', MerchantOnlinePayment::STATUS_PENDING)
                ->update(['checked_at' => date('Y-m-d H:i:s'), 'check_message' => $message]);
        }

        return $this->paymentDao->find($p->id);
    }

    /**
     * 后台查找这单可能的到账记录（还没入过账的收入流水）：
     * 填了支付宝订单号的单，查这个订单号的流水（金额不一样的也列出来，后台核实后按流水金额入账）；
     * 改版前的单没有订单号，从下单起（最长 31 天）找金额相等的，备注是这单单号的排前面。
     *
     * @return list<array{account_log_id: string, amount: string, trans_at: null|string, alipay_order_no: null|string, payer_account: null|string, memo: null|string, memo_matches: bool}>
     */
    public function candidates(MerchantOnlinePayment $p): array
    {
        $account = $p->alipay_account_id === null ? null : $this->accountDao->find($p->alipay_account_id);
        if ($account === null) {
            throw new HttpException(422, '这笔单的收款账户不存在');
        }
        try {
            if ($p->alipay_order_no !== null) {
                [$start, $end] = self::window($p->alipay_order_no);
                $entries = $this->entries($account, $start, $end, $p->alipay_order_no);
            } else {
                $start = $p->created_at->getTimestamp() - self::CLOCK_TOLERANCE;
                $end = min(time() + 1, $start + self::MAX_QUERY_DAYS * 86400);
                $entries = array_values(array_filter(
                    $this->entries($account, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end)),
                    static fn (array $e) => $e['amount'] === $p->pay_amount && $e['trans_at'] !== null && strtotime($e['trans_at']) >= $start,
                ));
            }
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        $used = $this->usedLogIds($entries);
        $result = [];
        foreach ($entries as $entry) {
            if (! isset($used[$entry['account_log_id']])) {
                $result[] = $entry + ['memo_matches' => $p->alipay_order_no !== null || ($entry['memo'] !== null && str_contains($entry['memo'], $p->order_no))];
            }
        }
        usort($result, static fn (array $a, array $b) => [$b['memo_matches'], $a['trans_at']] <=> [$a['memo_matches'], $b['trans_at']]);

        return $result;
    }

    /**
     * 入账：带条件改成已到账（同一条流水只能入一单），同一个事务里给商户加款，金额是流水的金额（没有流水时是单上的金额）。
     * 被别人先处理了返回 false。
     *
     * @param null|array<string, mixed> $entry 匹配到的流水（entries() 的一条），后台没找到流水直接确认时为 null
     */
    public function credit(MerchantOnlinePayment $p, ?array $entry, string $confirmType, ?int $adminUserId, ?string $remark): bool
    {
        $amount = isset($entry['amount']) ? (string) $entry['amount'] : $p->pay_amount;
        try {
            return Db::transaction(function () use ($p, $entry, $amount, $confirmType, $adminUserId, $remark) {
                $ok = $this->paymentDao->newQuery()
                    ->where('id', $p->id)
                    ->whereIn('status', [MerchantOnlinePayment::STATUS_PENDING, MerchantOnlinePayment::STATUS_EXPIRED, MerchantOnlinePayment::STATUS_CANCELLED])
                    ->update([
                        'status' => MerchantOnlinePayment::STATUS_PAID,
                        'pay_amount' => $amount,
                        'paid_at' => date('Y-m-d H:i:s'),
                        'checked_at' => date('Y-m-d H:i:s'),
                        'check_message' => null,
                        'confirm_type' => $confirmType,
                        'account_log_id' => $entry['account_log_id'] ?? null,
                        // 改版前的单没有订单号，用流水上的；填了订单号的单流水上的就是这个
                        'alipay_order_no' => $p->alipay_order_no ?? $entry['alipay_order_no'] ?? null,
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
                $log = $this->balanceService->change($p->merchant_id, MerchantBalanceLog::TYPE_RECHARGE, $amount, null, "支付宝转账 {$p->order_no} {$note}", $adminUserId);
                $this->paymentDao->newQuery()->where('id', $p->id)->update(['balance_log_id' => $log->id]);
                $this->logger()->info('支付宝转账入账', ['order_no' => $p->order_no, 'merchant_id' => $p->merchant_id, 'amount' => $amount, 'confirm_type' => $confirmType, 'account_log_id' => $entry['account_log_id'] ?? null]);

                return true;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'account_log_id_unique') || str_contains($e->getMessage(), 'alipay_order_no_unique')) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * 同一个订单号已经有单了：是这个商户没到账的单就改金额重新查，否则不能再提交。
     *
     * @param array<string, mixed> $attrs
     */
    private function reopen(MerchantOnlinePayment $existing, int $merchantId, array $attrs): MerchantOnlinePayment
    {
        if ($existing->status === MerchantOnlinePayment::STATUS_PAID) {
            throw new HttpException(422, self::MESSAGE_USED);
        }
        if ($existing->merchant_id !== $merchantId) {
            throw new HttpException(422, '这个支付宝订单号已经被提交过了，如有疑问请联系平台');
        }
        $this->throttle($merchantId);
        if (! $this->paymentDao->transition($existing->id, $existing->status, MerchantOnlinePayment::STATUS_PENDING, $attrs)) {
            throw new HttpException(422, '这笔单刚被处理过，请刷新后再试');
        }

        return $this->paymentDao->find($existing->id);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function createPending(int $merchantId, string $alipayOrderNo, array $attrs): MerchantOnlinePayment
    {
        for ($try = 1; $try <= 5; ++$try) {
            try {
                return $this->paymentDao->create($attrs + [
                    'order_no' => self::ORDER_NO_PREFIX . date('YmdHis') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
                    'merchant_id' => $merchantId,
                    'alipay_order_no' => $alipayOrderNo,
                    'status' => MerchantOnlinePayment::STATUS_PENDING,
                ]);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'alipay_order_no_unique')) {
                    // 同时提交了同一个订单号
                    throw new HttpException(422, '这个支付宝订单号已经被提交过了，请刷新后查看');
                }
                if (! str_contains($e->getMessage(), 'order_no_unique')) {
                    throw $e;
                }
            }
        }
        throw new HttpException(422, '提交的人太多了，请稍后再试');
    }

    /**
     * 查支付宝并在对得上时入账，返回没入账的原因（入账了返回 null）。
     */
    private function tryCredit(MerchantOnlinePayment $p): ?string
    {
        $account = $p->alipay_account_id === null ? null : $this->accountDao->find($p->alipay_account_id);
        if ($account === null) {
            return '收款账户不存在，请联系平台';
        }
        [$start, $end] = self::window($p->alipay_order_no);
        try {
            $entries = $this->entries($account, $start, $end, $p->alipay_order_no);
        } catch (RuntimeException) {
            return self::MESSAGE_FAILED;
        }
        if ($entries === []) {
            return self::MESSAGE_NOT_FOUND;
        }
        $used = $this->usedLogIds($entries);
        $unused = array_values(array_filter($entries, static fn (array $e) => ! isset($used[$e['account_log_id']])));
        if ($unused === []) {
            return self::MESSAGE_USED;
        }
        foreach ($unused as $entry) {
            if ($entry['amount'] === $p->amount) {
                return $this->credit($p, $entry, MerchantOnlinePayment::CONFIRM_AUTO, null, null) ? null : '这笔单已经处理过了，请刷新查看';
            }
        }
        $this->logger()->info('支付宝转账金额不符', ['order_no' => $p->order_no, 'alipay_order_no' => $p->alipay_order_no, 'amount' => $p->amount, 'found' => array_column($unused, 'amount')]);

        return self::MESSAGE_AMOUNT;
    }

    private function throttle(int $merchantId): void
    {
        if (! $this->lock->once('alipay_transfer:check:' . $merchantId, self::CHECK_INTERVAL)) {
            throw new HttpException(429, '查询太频繁了，请稍等几秒再试');
        }
    }

    /**
     * 按订单号查流水的时间范围：往前 30 天到现在。支付宝订单号前 8 位是交易日期，能认出来就从前一天开始查，范围小查得快。
     *
     * @return array{0: string, 1: string}
     */
    private static function window(string $alipayOrderNo): array
    {
        $now = time();
        $start = $now - self::LOOKBACK_DAYS * 86400;
        if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $alipayOrderNo, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $day = (int) mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
            $start = min($now - 86400, max($start, $day - 86400));
        }

        // 结束时间不包含，往后一秒把这一秒的流水也查进来
        return [date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $now + 1)];
    }

    /**
     * 查账务明细，整理成收入流水（入账时间升序）。支出、没有流水号、金额不对的丢掉；按订单号查时只留这个订单号的。
     *
     * @return list<array{account_log_id: string, amount: string, trans_at: null|string, alipay_order_no: null|string, payer_account: null|string, memo: null|string}>
     */
    private function entries(AlipayAccount $account, string $start, string $end, ?string $alipayOrderNo = null): array
    {
        try {
            $items = $this->client($account)->accountLogs($start, $end, $alipayOrderNo);
        } catch (Throwable $e) {
            $this->logger()->error('查询支付宝账务明细失败', ['alipay_account_id' => $account->id, 'start' => $start, 'end' => $end, 'alipay_order_no' => $alipayOrderNo, 'error' => $e->getMessage()]);
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
        $entries = [];
        foreach ($items as $item) {
            $entry = self::entry($item);
            if ($entry !== null && ($alipayOrderNo === null || $entry['alipay_order_no'] === $alipayOrderNo)) {
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
