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

namespace App\Service\MerchantPortal;

use App\Dao\MerchantFundingRequestDao;
use App\Dao\MerchantPayerAccountDao;
use App\Dao\MerchantReceivingAccountDao;
use App\Dao\ReceivingAccountDao;
use App\Model\Merchant;
use App\Model\MerchantFundingRequest;
use App\Model\MerchantPayerAccount;
use App\Model\MerchantUser;
use App\Model\ReceivingAccount;
use App\Service\AbstractService;
use App\Service\Admin\ValidatesAdminInput;
use App\Service\FiltersByDateRange;
use App\Service\Funding\PaymentAccountRules;
use App\Service\Storage\ObjectStorageService;
use DateTime;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 商户后台 - 加款：付款账号（先给平台审核）、平台分配的收款账户、提交加款申请（docs/project.md 5.1）。
 *
 * - 付款账号新增、修改后都是待审核，审核通过才能用来提交申请；
 * - 提交申请：选审核通过的付款账号、分配给自己且启用的收款账户，填金额、打款时间、备注，上传 1~5 张凭证；
 *   付款账号、收款账户存快照，之后改了也不影响这笔申请；
 * - 所有查询都按当前登录商户过滤。
 */
class MerchantFundingService extends AbstractService
{
    use FiltersByDateRange;
    use ValidatesAdminInput;

    private const MAX_PER_PAGE = 100;

    private const MAX_PAYER_ACCOUNTS = 20;

    private const MAX_VOUCHERS = 5;

    /** 打款时间最多比现在晚这么多（秒），容忍两边时钟误差 */
    private const PAID_AT_FUTURE_TOLERANCE = 600;

    #[Inject]
    protected MerchantPayerAccountDao $payerDao;

    #[Inject]
    protected ReceivingAccountDao $receivingDao;

    #[Inject]
    protected MerchantReceivingAccountDao $assignmentDao;

    #[Inject]
    protected MerchantFundingRequestDao $requestDao;

    #[Inject]
    protected ObjectStorageService $storage;

    #[Inject]
    protected PaymentAccountRules $rules;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @return list<array<string, mixed>>
     */
    public function payerAccounts(Merchant $merchant): array
    {
        return $this->payerDao->newQuery()->where('merchant_id', $merchant->id)->orderByDesc('id')->get()
            ->map(fn (MerchantPayerAccount $a) => $this->formatPayer($a))->values()->all();
    }

    /**
     * @param array<string, mixed> $data type / account_name / account_no / bank_name
     * @return array<string, mixed>
     */
    public function createPayer(Merchant $merchant, MerchantUser $user, array $data): array
    {
        $attrs = $this->rules->validate($data);
        if ($this->payerDao->newQuery()->where('merchant_id', $merchant->id)->count() >= self::MAX_PAYER_ACCOUNTS) {
            throw new HttpException(422, '付款账号最多 ' . self::MAX_PAYER_ACCOUNTS . ' 个，请修改或删除不用的');
        }
        $account = $this->payerDao->create($attrs + ['merchant_id' => $merchant->id, 'status' => MerchantPayerAccount::STATUS_PENDING]);
        $this->log('商户新增付款账号', $merchant, $user, ['payer_account_id' => $account->id]);

        return $this->formatPayer($account);
    }

    /**
     * 改完回到待审核，审核通过前不能用来提交申请。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updatePayer(Merchant $merchant, MerchantUser $user, int $id, array $data): array
    {
        $account = $this->findPayer($merchant, $id);
        $this->payerDao->update($account->id, $this->rules->validate($data) + [
            'status' => MerchantPayerAccount::STATUS_PENDING,
            'reject_reason' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
        $this->log('商户修改付款账号', $merchant, $user, ['payer_account_id' => $account->id]);

        return $this->formatPayer($this->findPayer($merchant, $account->id));
    }

    public function deletePayer(Merchant $merchant, MerchantUser $user, int $id): void
    {
        $account = $this->findPayer($merchant, $id);
        $this->payerDao->delete($account->id);
        $this->log('商户删除付款账号', $merchant, $user, ['payer_account_id' => $account->id]);
    }

    /**
     * 分配给这个商户、且启用的收款账户（打款给这些账户）。
     *
     * @return list<array<string, mixed>>
     */
    public function receivingAccounts(Merchant $merchant): array
    {
        $ids = $this->assignmentDao->accountIdsForMerchant($merchant->id);
        if ($ids === []) {
            return [];
        }

        return $this->receivingDao->newQuery()->whereIn('id', $ids)->where('status', ReceivingAccount::STATUS_ACTIVE)->orderBy('id')->get()
            ->map(static fn (ReceivingAccount $a) => ['id' => $a->id] + PaymentAccountRules::present($a->type, $a->account_name, $a->account_no, $a->bank_name) + ['remark' => $a->remark])
            ->values()->all();
    }

    /**
     * 上传一张凭证图片，返回 key（提交申请时带上）和预览链接。
     *
     * @return array{key: string, url: string}
     */
    public function uploadVoucher(Merchant $merchant, mixed $file): array
    {
        $key = $this->storage->uploadImage($this->voucherDir($merchant), $file);

        return ['key' => $key, 'url' => $this->storage->url($key)];
    }

    /**
     * @param array<string, mixed> $query status / created_from / created_to
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function requests(Merchant $merchant, array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));
        $builder = $this->requestDao->newQuery()->where('merchant_id', $merchant->id);
        $status = $query['status'] ?? null;
        if (in_array($status, [MerchantFundingRequest::STATUS_PENDING, MerchantFundingRequest::STATUS_APPROVED, MerchantFundingRequest::STATUS_REJECTED], true)) {
            $builder->where('status', $status);
        }
        $this->applyDateRange($builder, $query, $status === MerchantFundingRequest::STATUS_PENDING);
        $total = (clone $builder)->count();
        $rows = $builder->orderByDesc('id')->forPage($page, $perPage)->get();

        return [
            'data' => $rows->map(fn (MerchantFundingRequest $r) => $this->formatRequest($r, false))->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function request(Merchant $merchant, int $id): array
    {
        $r = $this->requestDao->newQuery()->where('merchant_id', $merchant->id)->where('id', $id)->first();
        if ($r === null) {
            throw new HttpException(404, '加款申请不存在');
        }

        return $this->formatRequest($r, true);
    }

    /**
     * @param array<string, mixed> $data amount / payer_account_id / receiving_account_id / paid_at / remark / vouchers
     * @return array<string, mixed>
     */
    public function createRequest(Merchant $merchant, MerchantUser $user, array $data): array
    {
        $amount = $this->money($data['amount'] ?? null, '加款金额');
        if (bccomp($amount, '10000000', 2) > 0) {
            throw new HttpException(422, '单笔加款金额不能超过 1000 万');
        }
        $payer = is_numeric($data['payer_account_id'] ?? null)
            ? $this->payerDao->newQuery()->where('merchant_id', $merchant->id)->where('id', (int) $data['payer_account_id'])->first()
            : null;
        if ($payer === null || $payer->status !== MerchantPayerAccount::STATUS_APPROVED) {
            throw new HttpException(422, '请选择审核通过的付款账号');
        }
        $receivingId = is_numeric($data['receiving_account_id'] ?? null) ? (int) $data['receiving_account_id'] : 0;
        $receiving = in_array($receivingId, $this->assignmentDao->accountIdsForMerchant($merchant->id), true) ? $this->receivingDao->find($receivingId) : null;
        if ($receiving === null || $receiving->status !== ReceivingAccount::STATUS_ACTIVE) {
            throw new HttpException(422, '请选择平台分配给你的收款账户');
        }
        $paidAt = $this->paidAt($data['paid_at'] ?? null);
        $vouchers = $this->vouchers($merchant, $data['vouchers'] ?? null);

        $r = $this->requestDao->create([
            'merchant_id' => $merchant->id,
            'merchant_user_id' => $user->id,
            'amount' => $amount,
            'payer_account_id' => $payer->id,
            'payer_type' => $payer->type,
            'payer_account_name' => $payer->account_name,
            'payer_account_no' => $payer->account_no,
            'payer_bank_name' => $payer->bank_name,
            'receiving_account_id' => $receiving->id,
            'receiving_type' => $receiving->type,
            'receiving_account_name' => $receiving->account_name,
            'receiving_account_no' => $receiving->account_no,
            'receiving_bank_name' => $receiving->bank_name,
            'paid_at' => $paidAt,
            'remark' => $this->remark($data['remark'] ?? null),
            'vouchers' => $vouchers,
            'status' => MerchantFundingRequest::STATUS_PENDING,
        ]);
        $this->log('商户提交加款申请', $merchant, $user, ['funding_request_id' => $r->id, 'amount' => $amount]);

        return $this->formatRequest($this->requestDao->find($r->id), true);
    }

    private function findPayer(Merchant $merchant, int $id): MerchantPayerAccount
    {
        $account = $this->payerDao->newQuery()->where('merchant_id', $merchant->id)->where('id', $id)->first();
        if ($account === null) {
            throw new HttpException(404, '付款账号不存在');
        }

        return $account;
    }

    private function paidAt(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        $time = DateTime::createFromFormat('!Y-m-d H:i:s', $value) ?: DateTime::createFromFormat('!Y-m-d H:i', $value);
        if ($time === false) {
            throw new HttpException(422, '请填写打款时间');
        }
        if ($time->getTimestamp() > time() + self::PAID_AT_FUTURE_TOLERANCE) {
            throw new HttpException(422, '打款时间不能晚于现在');
        }

        return $time->format('Y-m-d H:i:s');
    }

    /**
     * 凭证只能是这个商户自己上传的图片。
     *
     * @return list<string>
     */
    private function vouchers(Merchant $merchant, mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            throw new HttpException(422, '请上传打款凭证');
        }
        if (count($value) > self::MAX_VOUCHERS) {
            throw new HttpException(422, '凭证最多 ' . self::MAX_VOUCHERS . ' 张');
        }
        $keys = [];
        foreach ($value as $key) {
            if (! is_string($key) || ! $this->storage->isUnder($key, $this->voucherDir($merchant))) {
                throw new HttpException(422, '凭证图片无效，请重新上传');
            }
            $keys[] = $key;
        }

        return array_values(array_unique($keys));
    }

    private function voucherDir(Merchant $merchant): string
    {
        return "vouchers/{$merchant->id}";
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPayer(MerchantPayerAccount $a): array
    {
        return ['id' => $a->id]
            + PaymentAccountRules::present($a->type, $a->account_name, $a->account_no, $a->bank_name)
            + [
                'status' => $a->status,
                'reject_reason' => $a->reject_reason,
                'reviewed_at' => $a->reviewed_at?->toDateTimeString(),
                'created_at' => $a->created_at?->toDateTimeString(),
                'updated_at' => $a->updated_at?->toDateTimeString(),
            ];
    }

    /**
     * 不返回审核人、资金流水 id 等平台内部信息。
     *
     * @return array<string, mixed>
     */
    private function formatRequest(MerchantFundingRequest $r, bool $withUrls): array
    {
        $result = [
            'id' => $r->id,
            'amount' => $r->amount,
            'paid_at' => $r->paid_at?->toDateTimeString(),
            'remark' => $r->remark,
            'voucher_count' => count($r->vouchers ?? []),
            'status' => $r->status,
            'review_remark' => $r->review_remark,
            'reviewed_at' => $r->reviewed_at?->toDateTimeString(),
            'created_at' => $r->created_at?->toDateTimeString(),
        ] + $this->rules->snapshots($r);
        if ($withUrls) {
            try {
                $result['voucher_urls'] = array_map(fn (string $key) => $this->storage->url($key), $r->vouchers ?? []);
            } catch (Throwable) {
                $result['voucher_urls'] = [];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $message, Merchant $merchant, MerchantUser $user, array $context): void
    {
        $this->loggerFactory->get('merchant')->info($message, ['merchant_id' => $merchant->id, 'user' => $user->username] + $context);
    }
}
