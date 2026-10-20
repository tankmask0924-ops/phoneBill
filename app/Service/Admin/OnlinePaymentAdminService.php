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

namespace App\Service\Admin;

use App\Dao\AdminOperationLogDao;
use App\Dao\AdminUserDao;
use App\Dao\AlipayAccountDao;
use App\Dao\MerchantDao;
use App\Dao\MerchantOnlinePaymentDao;
use App\Model\AdminUser;
use App\Model\MerchantOnlinePayment;
use App\Service\AbstractService;
use App\Service\FiltersByDateRange;
use App\Service\Payment\AlipayTransferService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户中心 - 加款申请 - 在线支付（支付宝扫码转账）：查看支付单；自动核对没认上的（过期、取消、核对期内备注不对），
 * 查找到账记录后按某条流水确认入账，或者核实后不带流水直接确认入账（必须写备注）。规则见 AlipayTransferService。
 */
class OnlinePaymentAdminService extends AbstractService
{
    use FiltersByDateRange;

    private const MODULE = 'funding';

    private const MAX_PER_PAGE = 100;

    private const STATUSES = [MerchantOnlinePayment::STATUS_PENDING, MerchantOnlinePayment::STATUS_PAID, MerchantOnlinePayment::STATUS_EXPIRED, MerchantOnlinePayment::STATUS_CANCELLED];

    #[Inject]
    protected MerchantOnlinePaymentDao $paymentDao;

    #[Inject]
    protected AlipayTransferService $transferService;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    #[Inject]
    protected AlipayAccountDao $accountDao;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @param array<string, mixed> $query status / merchant_id / alipay_account_id / keyword（支付单号）/ created_from / created_to
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));
        $builder = $this->paymentDao->newQuery();
        $status = $query['status'] ?? null;
        if (in_array($status, self::STATUSES, true)) {
            $builder->where('status', $status);
        }
        if (isset($query['merchant_id']) && is_numeric($query['merchant_id'])) {
            $builder->where('merchant_id', (int) $query['merchant_id']);
        }
        if (isset($query['alipay_account_id']) && is_numeric($query['alipay_account_id'])) {
            $builder->where('alipay_account_id', (int) $query['alipay_account_id']);
        }
        $keyword = trim(is_string($query['keyword'] ?? null) ? $query['keyword'] : '');
        if ($keyword !== '') {
            $builder->where('order_no', $keyword);
        }
        // 待支付的、按单号查的不限日期
        $this->applyDateRange($builder, $query, $status === MerchantOnlinePayment::STATUS_PENDING || $keyword !== '');
        $total = (clone $builder)->count();
        $rows = $builder->orderByDesc('id')->forPage($page, $perPage)->get();
        $merchants = $this->merchantNames($rows->pluck('merchant_id')->all());
        $admins = $this->adminNames($rows->pluck('confirmed_by')->all());
        $accounts = $this->accountDao->names($rows->pluck('alipay_account_id')->all());

        return [
            'data' => $rows->map(fn (MerchantOnlinePayment $p) => $this->format($p, $merchants, $admins, $accounts))->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $id): array
    {
        $p = $this->find($id);

        return $this->format($p, $this->merchantNames([$p->merchant_id]), $this->adminNames([$p->confirmed_by]), $this->accountDao->names([$p->alipay_account_id]));
    }

    /**
     * 查找这单可能的到账记录（见 AlipayTransferService::candidates()）。
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(int $id): array
    {
        $p = $this->find($id);
        if ($p->status === MerchantOnlinePayment::STATUS_PAID) {
            throw new HttpException(422, '这笔单已经入账了');
        }

        return $this->transferService->candidates($p);
    }

    /**
     * 确认入账：带 account_log_id 时必须是查找到账记录里的一条；不带时是核实后直接确认，必须写备注。
     *
     * @return array<string, mixed>
     */
    public function confirm(AdminUser $operator, int $id, mixed $accountLogId, mixed $remark, ?string $ip): array
    {
        $p = $this->find($id);
        if ($p->status === MerchantOnlinePayment::STATUS_PAID) {
            throw new HttpException(422, '这笔单已经入账了');
        }
        $remark = trim(is_string($remark) ? $remark : '');
        $remark = $remark === '' ? null : mb_substr($remark, 0, 255);
        $entry = null;
        $accountLogId = is_scalar($accountLogId) ? trim((string) $accountLogId) : '';
        if ($accountLogId !== '') {
            foreach ($this->transferService->candidates($p) as $candidate) {
                if ($candidate['account_log_id'] === $accountLogId) {
                    $entry = $candidate;
                    break;
                }
            }
            if ($entry === null) {
                throw new HttpException(422, '这条流水不在可入账的到账记录里（可能已经入过账），请重新查找');
            }
        } elseif ($remark === null) {
            throw new HttpException(422, '没有选到账记录时必须写备注，说明在哪里核实了到账');
        }
        if (! $this->transferService->credit($p, $entry, MerchantOnlinePayment::CONFIRM_MANUAL, $operator->id, $remark)) {
            throw new HttpException(422, '这笔单已经处理过了，或者这条流水已经入过账');
        }
        $this->operationLogDao->record($operator->id, self::MODULE, 'confirm_online_payment', 'merchant_online_payment', $p->id, ['status' => $p->status], [
            'status' => MerchantOnlinePayment::STATUS_PAID,
            'merchant_id' => $p->merchant_id,
            'amount' => $p->pay_amount,
            'account_log_id' => $entry['account_log_id'] ?? null,
            'remark' => $remark,
        ], $ip);

        return $this->detail($p->id);
    }

    private function find(int $id): MerchantOnlinePayment
    {
        $p = $this->paymentDao->find($id);
        if ($p === null) {
            throw new HttpException(404, '支付单不存在');
        }

        return $p;
    }

    /**
     * @param array<int, string> $merchants
     * @param array<int, string> $admins
     * @param array<int, string> $accounts
     * @return array<string, mixed>
     */
    private function format(MerchantOnlinePayment $p, array $merchants, array $admins, array $accounts): array
    {
        return [
            'id' => $p->id,
            'order_no' => $p->order_no,
            'merchant_id' => $p->merchant_id,
            'merchant_name' => $merchants[$p->merchant_id] ?? null,
            'amount' => $p->amount,
            'pay_amount' => $p->pay_amount,
            'alipay_account_id' => $p->alipay_account_id,
            'alipay_account_name' => $p->alipay_account_id ? ($accounts[$p->alipay_account_id] ?? null) : null,
            'receive_account' => $p->receive_account,
            'receive_name' => $p->receive_name,
            'status' => $p->status,
            'expires_at' => $p->expires_at->toDateTimeString(),
            'checking_until' => $p->checking_until->toDateTimeString(),
            'paid_at' => $p->paid_at?->toDateTimeString(),
            'confirm_type' => $p->confirm_type,
            'account_log_id' => $p->account_log_id,
            'alipay_order_no' => $p->alipay_order_no,
            'payer_account' => $p->payer_account,
            'trans_memo' => $p->trans_memo,
            'trans_at' => $p->trans_at?->toDateTimeString(),
            'remark' => $p->remark,
            'confirmer_name' => $p->confirmed_by ? ($admins[$p->confirmed_by] ?? null) : null,
            'balance_log_id' => $p->balance_log_id,
            'created_at' => $p->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @param list<mixed> $ids
     * @return array<int, string>
     */
    private function merchantNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : $this->merchantDao->newQuery()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @param list<mixed> $ids
     * @return array<int, string>
     */
    private function adminNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : $this->adminUserDao->newQuery()->whereIn('id', $ids)->pluck('real_name', 'id')->all();
    }
}
