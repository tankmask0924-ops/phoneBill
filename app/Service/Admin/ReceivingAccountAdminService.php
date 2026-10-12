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
use App\Dao\MerchantDao;
use App\Dao\MerchantReceivingAccountDao;
use App\Dao\ReceivingAccountDao;
use App\Model\AdminUser;
use App\Model\ReceivingAccount;
use App\Service\AbstractService;
use App\Service\Funding\PaymentAccountRules;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户中心 - 收款配置：平台的收款账户（支付宝 / 对公），分配给商户后，商户提交加款申请时才能选。
 *
 * - 账户不多，列表不分页；停用后商户不能再选，已提交的申请不受影响（存了快照）；
 * - 不提供删除，不用了就停用。
 */
class ReceivingAccountAdminService extends AbstractService
{
    use ValidatesAdminInput;

    private const MODULE = 'funding';

    #[Inject]
    protected ReceivingAccountDao $accountDao;

    #[Inject]
    protected MerchantReceivingAccountDao $assignmentDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected PaymentAccountRules $rules;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $accounts = $this->accountDao->newQuery()->orderBy('status')->orderByDesc('id')->get()->all();

        return $this->formatMany($accounts);
    }

    /**
     * @param array<string, mixed> $data type / account_name / account_no / bank_name / remark
     * @return array<string, mixed>
     */
    public function create(AdminUser $operator, array $data, ?string $ip): array
    {
        $account = $this->accountDao->create($this->rules->validate($data) + [
            'status' => ReceivingAccount::STATUS_ACTIVE,
            'remark' => $this->remark($data['remark'] ?? null),
        ]);
        $after = $this->formatMany([$account])[0];
        $this->operationLogDao->record($operator->id, self::MODULE, 'create_receiving_account', 'receiving_account', $account->id, null, $after, $ip);

        return $after;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(AdminUser $operator, int $id, array $data, ?string $ip): array
    {
        $account = $this->findOrFail($id);
        $before = $this->formatMany([$account])[0];
        $this->accountDao->update($account->id, $this->rules->validate($data) + ['remark' => $this->remark($data['remark'] ?? null)]);
        $after = $this->formatMany([$this->findOrFail($account->id)])[0];
        $this->operationLogDao->record($operator->id, self::MODULE, 'update_receiving_account', 'receiving_account', $account->id, $before, $after, $ip);

        return $after;
    }

    /**
     * @return array<string, mixed>
     */
    public function changeStatus(AdminUser $operator, int $id, mixed $status, ?string $ip): array
    {
        $status = $this->status($status);
        $account = $this->findOrFail($id);
        if ($status !== $account->status) {
            $this->accountDao->update($account->id, ['status' => $status]);
            $this->operationLogDao->record(
                $operator->id,
                self::MODULE,
                $status === ReceivingAccount::STATUS_ACTIVE ? 'enable_receiving_account' : 'disable_receiving_account',
                'receiving_account',
                $account->id,
                ['status' => $account->status],
                ['status' => $status],
                $ip
            );
        }

        return $this->formatMany([$this->findOrFail($account->id)])[0];
    }

    /**
     * 把这个收款账户分配给哪些商户（整体替换）。
     *
     * @return array<string, mixed>
     */
    public function assignMerchants(AdminUser $operator, int $id, mixed $merchantIds, ?string $ip): array
    {
        $account = $this->findOrFail($id);
        if (! is_array($merchantIds)) {
            throw new HttpException(422, 'merchant_ids 必须是数组');
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($merchantIds, 'is_numeric'))));
        $found = $this->merchantDao->newQuery()->whereIn('id', $ids ?: [0])->count();
        if ($found !== count($ids)) {
            throw new HttpException(422, '有商户不存在');
        }
        $before = $this->assignmentDao->merchantIdsByAccount([$account->id])[$account->id] ?? [];
        Db::transaction(fn () => $this->assignmentDao->replaceMerchants($account->id, $ids));
        sort($before);
        sort($ids);
        $this->operationLogDao->record($operator->id, self::MODULE, 'assign_receiving_account', 'receiving_account', $account->id, ['merchant_ids' => $before], ['merchant_ids' => $ids], $ip);

        return $this->formatMany([$account])[0];
    }

    private function findOrFail(int $id): ReceivingAccount
    {
        $account = $this->accountDao->find($id);
        if (! $account) {
            throw new HttpException(404, '收款账户不存在');
        }

        return $account;
    }

    /**
     * @param list<ReceivingAccount> $accounts
     * @return list<array<string, mixed>>
     */
    private function formatMany(array $accounts): array
    {
        $assigned = $this->assignmentDao->merchantIdsByAccount(array_map(static fn (ReceivingAccount $a) => $a->id, $accounts));
        $merchantIds = array_values(array_unique(array_merge([], ...array_values($assigned))));
        $names = $merchantIds === [] ? [] : $this->merchantDao->newQuery()->whereIn('id', $merchantIds)->pluck('name', 'id')->all();

        return array_map(static function (ReceivingAccount $a) use ($assigned, $names) {
            $ids = $assigned[$a->id] ?? [];
            sort($ids);

            return ['id' => $a->id]
                + PaymentAccountRules::present($a->type, $a->account_name, $a->account_no, $a->bank_name)
                + [
                    'status' => $a->status,
                    'remark' => $a->remark,
                    'merchant_ids' => $ids,
                    'merchant_names' => array_values(array_map(static fn ($id) => $names[$id] ?? "#{$id}", $ids)),
                    'created_at' => $a->created_at?->toDateTimeString(),
                    'updated_at' => $a->updated_at?->toDateTimeString(),
                ];
        }, $accounts);
    }
}
