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
use App\Dao\MerchantDao;
use App\Dao\MerchantFundingRequestDao;
use App\Dao\MerchantPayerAccountDao;
use App\Model\AdminUser;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantFundingRequest;
use App\Model\MerchantPayerAccount;
use App\Service\AbstractService;
use App\Service\FiltersByDateRange;
use App\Service\Funding\PaymentAccountRules;
use App\Service\Merchant\MerchantBalanceService;
use App\Service\Storage\ObjectStorageService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Throwable;

/**
 * 商户中心 - 加款申请：审核商户的付款账号、审核加款申请（docs/project.md 4.6.1）。
 *
 * - 付款账号：待审核的才能审；商户修改后回到待审核，所以审核时按状态做条件更新，审的一定是商户最后提交的内容；
 * - 加款申请：只能按申请金额入账（不能改金额，不对就驳回）；通过时在同一个事务里改状态、给商户加款、写资金流水，
 *   状态按条件更新，重复点、两个人同时审都只会入账一次；驳回必须写原因。
 */
class FundingAdminService extends AbstractService
{
    use FiltersByDateRange;
    use ValidatesAdminInput;

    private const MODULE = 'funding';

    private const MAX_PER_PAGE = 100;

    private const STATUSES = [MerchantFundingRequest::STATUS_PENDING, MerchantFundingRequest::STATUS_APPROVED, MerchantFundingRequest::STATUS_REJECTED];

    #[Inject]
    protected MerchantPayerAccountDao $payerDao;

    #[Inject]
    protected MerchantFundingRequestDao $requestDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    #[Inject]
    protected MerchantBalanceService $balanceService;

    #[Inject]
    protected ObjectStorageService $storage;

    #[Inject]
    protected PaymentAccountRules $rules;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @param array<string, mixed> $query status / merchant_id
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function payerAccounts(array $query): array
    {
        [$page, $perPage] = $this->paging($query);
        $builder = $this->payerDao->newQuery();
        if (in_array($query['status'] ?? null, self::STATUSES, true)) {
            $builder->where('status', $query['status']);
        }
        if (isset($query['merchant_id']) && is_numeric($query['merchant_id'])) {
            $builder->where('merchant_id', (int) $query['merchant_id']);
        }
        $total = (clone $builder)->count();
        $rows = $builder->orderByDesc('id')->forPage($page, $perPage)->get();
        $merchants = $this->merchantNames($rows->pluck('merchant_id')->all());
        $admins = $this->adminNames($rows->pluck('reviewed_by')->all());

        return [
            'data' => $rows->map(fn (MerchantPayerAccount $a) => $this->formatPayer($a, $merchants, $admins))->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function approvePayer(AdminUser $operator, int $id, ?string $ip): array
    {
        $account = $this->findPayer($id);
        $ok = $this->payerDao->transition($account->id, MerchantPayerAccount::STATUS_PENDING, MerchantPayerAccount::STATUS_APPROVED, [
            'reject_reason' => null,
            'reviewed_by' => $operator->id,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        if (! $ok) {
            throw new HttpException(422, '这个付款账号不是待审核状态（可能已经审过，或商户刚改过请刷新）');
        }
        $this->operationLogDao->record($operator->id, self::MODULE, 'approve_payer_account', 'merchant_payer_account', $account->id, null, $this->payerSnapshot($account), $ip);

        return $this->payerDetail($account->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function rejectPayer(AdminUser $operator, int $id, mixed $reason, ?string $ip): array
    {
        $account = $this->findPayer($id);
        $reason = $this->requiredString($reason, '驳回原因', 255);
        $ok = $this->payerDao->transition($account->id, MerchantPayerAccount::STATUS_PENDING, MerchantPayerAccount::STATUS_REJECTED, [
            'reject_reason' => $reason,
            'reviewed_by' => $operator->id,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        if (! $ok) {
            throw new HttpException(422, '这个付款账号不是待审核状态（可能已经审过，或商户刚改过请刷新）');
        }
        $this->operationLogDao->record($operator->id, self::MODULE, 'reject_payer_account', 'merchant_payer_account', $account->id, null, $this->payerSnapshot($account) + ['reason' => $reason], $ip);

        return $this->payerDetail($account->id);
    }

    /**
     * @param array<string, mixed> $query status / merchant_id / created_from / created_to
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function requests(array $query): array
    {
        [$page, $perPage] = $this->paging($query);
        $builder = $this->requestDao->newQuery();
        $status = $query['status'] ?? null;
        if (in_array($status, self::STATUSES, true)) {
            $builder->where('status', $status);
        }
        if (isset($query['merchant_id']) && is_numeric($query['merchant_id'])) {
            $builder->where('merchant_id', (int) $query['merchant_id']);
        }
        // 看待审核的不限日期，免得漏审
        $this->applyDateRange($builder, $query, $status === MerchantFundingRequest::STATUS_PENDING);
        $total = (clone $builder)->count();
        $rows = $builder->orderByDesc('id')->forPage($page, $perPage)->get();
        $merchants = $this->merchantNames($rows->pluck('merchant_id')->all());
        $admins = $this->adminNames($rows->pluck('reviewed_by')->all());

        return [
            'data' => $rows->map(fn (MerchantFundingRequest $r) => $this->formatRequest($r, $merchants, $admins, false))->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 详情带凭证图片的临时链接（10 分钟有效）。
     *
     * @return array<string, mixed>
     */
    public function requestDetail(int $id): array
    {
        $r = $this->findRequest($id);

        return $this->formatRequest($r, $this->merchantNames([$r->merchant_id]), $this->adminNames([$r->reviewed_by]), true);
    }

    /**
     * 审核通过：按申请金额给商户加款。
     *
     * @return array<string, mixed>
     */
    public function approveRequest(AdminUser $operator, int $id, mixed $remark, ?string $ip): array
    {
        $r = $this->findRequest($id);
        $remark = $this->remark($remark);
        Db::transaction(function () use ($operator, $r, $remark) {
            $ok = $this->requestDao->transition($r->id, MerchantFundingRequest::STATUS_PENDING, MerchantFundingRequest::STATUS_APPROVED, [
                'review_remark' => $remark,
                'reviewed_by' => $operator->id,
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);
            if (! $ok) {
                throw new HttpException(422, '这笔申请已经处理过了');
            }
            $log = $this->balanceService->change($r->merchant_id, MerchantBalanceLog::TYPE_RECHARGE, $r->amount, null, "加款申请 #{$r->id} 审核通过", $operator->id);
            $this->requestDao->newQuery()->where('id', $r->id)->update(['balance_log_id' => $log->id]);
        });
        $this->operationLogDao->record($operator->id, self::MODULE, 'approve_funding_request', 'merchant_funding_request', $r->id, ['status' => $r->status], [
            'status' => MerchantFundingRequest::STATUS_APPROVED,
            'merchant_id' => $r->merchant_id,
            'amount' => $r->amount,
            'remark' => $remark,
        ], $ip);

        return $this->requestDetail($r->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function rejectRequest(AdminUser $operator, int $id, mixed $remark, ?string $ip): array
    {
        $r = $this->findRequest($id);
        $remark = $this->requiredString($remark, '驳回原因', 255);
        $ok = $this->requestDao->transition($r->id, MerchantFundingRequest::STATUS_PENDING, MerchantFundingRequest::STATUS_REJECTED, [
            'review_remark' => $remark,
            'reviewed_by' => $operator->id,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        if (! $ok) {
            throw new HttpException(422, '这笔申请已经处理过了');
        }
        $this->operationLogDao->record($operator->id, self::MODULE, 'reject_funding_request', 'merchant_funding_request', $r->id, ['status' => $r->status], [
            'status' => MerchantFundingRequest::STATUS_REJECTED,
            'merchant_id' => $r->merchant_id,
            'amount' => $r->amount,
            'remark' => $remark,
        ], $ip);

        return $this->requestDetail($r->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function payerDetail(int $id): array
    {
        $a = $this->findPayer($id);

        return $this->formatPayer($a, $this->merchantNames([$a->merchant_id]), $this->adminNames([$a->reviewed_by]));
    }

    private function findPayer(int $id): MerchantPayerAccount
    {
        $account = $this->payerDao->find($id);
        if (! $account) {
            throw new HttpException(404, '付款账号不存在');
        }

        return $account;
    }

    private function findRequest(int $id): MerchantFundingRequest
    {
        $r = $this->requestDao->find($id);
        if (! $r) {
            throw new HttpException(404, '加款申请不存在');
        }

        return $r;
    }

    /**
     * @return array<string, mixed>
     */
    private function payerSnapshot(MerchantPayerAccount $a): array
    {
        return ['merchant_id' => $a->merchant_id] + PaymentAccountRules::present($a->type, $a->account_name, $a->account_no, $a->bank_name);
    }

    /**
     * @param array<int, string> $merchants
     * @param array<int, string> $admins
     * @return array<string, mixed>
     */
    private function formatPayer(MerchantPayerAccount $a, array $merchants, array $admins): array
    {
        return ['id' => $a->id, 'merchant_id' => $a->merchant_id, 'merchant_name' => $merchants[$a->merchant_id] ?? null]
            + PaymentAccountRules::present($a->type, $a->account_name, $a->account_no, $a->bank_name)
            + [
                'status' => $a->status,
                'reject_reason' => $a->reject_reason,
                'reviewer_name' => $a->reviewed_by ? ($admins[$a->reviewed_by] ?? null) : null,
                'reviewed_at' => $a->reviewed_at?->toDateTimeString(),
                'created_at' => $a->created_at?->toDateTimeString(),
                'updated_at' => $a->updated_at?->toDateTimeString(),
            ];
    }

    /**
     * @param array<int, string> $merchants
     * @param array<int, string> $admins
     * @return array<string, mixed>
     */
    private function formatRequest(MerchantFundingRequest $r, array $merchants, array $admins, bool $withUrls): array
    {
        $result = [
            'id' => $r->id,
            'merchant_id' => $r->merchant_id,
            'merchant_name' => $merchants[$r->merchant_id] ?? null,
            'amount' => $r->amount,
            'paid_at' => $r->paid_at?->toDateTimeString(),
            'remark' => $r->remark,
            'voucher_count' => count($r->vouchers ?? []),
            'status' => $r->status,
            'review_remark' => $r->review_remark,
            'reviewer_name' => $r->reviewed_by ? ($admins[$r->reviewed_by] ?? null) : null,
            'reviewed_at' => $r->reviewed_at?->toDateTimeString(),
            'balance_log_id' => $r->balance_log_id,
            'created_at' => $r->created_at?->toDateTimeString(),
        ] + $this->rules->snapshots($r);
        if ($withUrls) {
            $result += $this->voucherUrls($r->vouchers ?? []);
        }

        return $result;
    }

    /**
     * 存储没配好或签名出错时不影响看详情，只是图片打不开。
     *
     * @param list<string> $keys
     * @return array{voucher_urls: list<string>, voucher_error: null|string}
     */
    private function voucherUrls(array $keys): array
    {
        try {
            return ['voucher_urls' => array_map(fn (string $key) => $this->storage->url($key), $keys), 'voucher_error' => null];
        } catch (Throwable $e) {
            return ['voucher_urls' => [], 'voucher_error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: int, 1: int}
     */
    private function paging(array $query): array
    {
        return [max(1, (int) ($query['page'] ?? 1)), min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)))];
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
