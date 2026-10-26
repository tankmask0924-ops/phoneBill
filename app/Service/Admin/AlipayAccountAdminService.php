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
use App\Dao\AlipayAccountDao;
use App\Dao\MerchantDao;
use App\Model\AdminUser;
use App\Model\AlipayAccount;
use App\Payment\AlipayClient;
use App\Security\Encrypter;
use App\Service\AbstractService;
use App\Service\Payment\AlipayTransferService;
use App\Service\Storage\ObjectStorageService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Throwable;

/**
 * 系统配置 - 支付宝转账加款账户（可以多个）：开放平台应用（AppID、应用私钥、支付宝公钥，用来按订单号查账务明细）、
 * 收款账号、固定收款码（图片存 COS，新建时必须上传）、单笔限额；新增、编辑、启用 / 停用（不提供删除，历史支付单要用）、
 * 测试连接。应用私钥加密存储、不回显，编辑时留空表示不改。
 *
 * 商户用哪个账户在商户列表的「收款配置」里指定（一个商户一个），没指定就不能用支付宝转账加款。
 */
class AlipayAccountAdminService extends AbstractService
{
    use ValidatesAdminInput;

    private const MODULE = 'system';

    /** 收款码图片在 COS 里的目录 */
    private const QR_CODE_DIR = 'alipay-qr';

    #[Inject]
    protected AlipayAccountDao $accountDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected AlipayTransferService $transferService;

    #[Inject]
    protected Encrypter $encrypter;

    #[Inject]
    protected ObjectStorageService $storage;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * 全部账户，带收款码预览链接和指定用它的商户。
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $accounts = $this->accountDao->newQuery()->orderBy('id')->get();
        $merchants = [];
        foreach ($this->merchantDao->newQuery()->whereIn('alipay_account_id', $accounts->pluck('id')->all() ?: [0])->orderBy('id')->get(['id', 'name', 'alipay_account_id']) as $m) {
            $merchants[$m->alipay_account_id][] = $m->name;
        }

        return $accounts->map(fn (AlipayAccount $a) => $this->format($a) + ['qr_code_url' => $this->qrCodeUrl($a), 'merchant_names' => $merchants[$a->id] ?? []])->values()->all();
    }

    /**
     * @param array<string, mixed> $data name / app_id / private_key / alipay_public_key / account / account_name / qr_code / min_amount / max_amount / remark
     * @return array<string, mixed>
     */
    public function create(AdminUser $operator, array $data, ?string $ip): array
    {
        $account = $this->accountDao->create($this->validate($data, null) + ['status' => AlipayAccount::STATUS_ACTIVE]);
        $after = $this->format($account);
        $this->operationLogDao->record($operator->id, self::MODULE, 'create_alipay_account', 'alipay_account', $account->id, null, $after, $ip);

        return $after;
    }

    /**
     * 改了收款账号、收款码、限额只影响之后提交的单，已提交的单用提交时的快照。
     *
     * @param array<string, mixed> $data 同 create()，private_key、qr_code 留空不改
     * @return array<string, mixed>
     */
    public function update(AdminUser $operator, int $id, array $data, ?string $ip): array
    {
        $account = $this->findOrFail($id);
        $before = $this->format($account);
        $this->accountDao->update($account->id, $this->validate($data, $account));
        $after = $this->format($this->findOrFail($account->id));
        $this->operationLogDao->record($operator->id, self::MODULE, 'update_alipay_account', 'alipay_account', $account->id, $before, $after, $ip);

        return $after;
    }

    /**
     * 上传收款码图片，返回 key（保存账户时传 qr_code）和预览链接。
     *
     * @return array{key: string, url: string}
     */
    public function uploadQrCode(mixed $file): array
    {
        $key = $this->storage->uploadImage(self::QR_CODE_DIR, $file);

        return ['key' => $key, 'url' => $this->storage->url($key)];
    }

    /**
     * 停用后指定了它的商户不能再提交转账，已提交的单还能重新查询。
     *
     * @return array<string, mixed>
     */
    public function changeStatus(AdminUser $operator, int $id, mixed $status, ?string $ip): array
    {
        $status = $this->status($status);
        $account = $this->findOrFail($id);
        if ($status !== $account->status) {
            $this->accountDao->update($account->id, ['status' => $status]);
            $this->operationLogDao->record($operator->id, self::MODULE, $status === AlipayAccount::STATUS_ACTIVE ? 'enable_alipay_account' : 'disable_alipay_account', 'alipay_account', $account->id, ['status' => $account->status], ['status' => $status], $ip);
        }

        return $this->format($this->findOrFail($account->id));
    }

    /**
     * 用保存的参数查最近一小时的账务明细，确认应用、密钥和接口权限都对。
     *
     * @return array{success: bool, message: string}
     */
    public function test(int $id): array
    {
        $account = $this->findOrFail($id);
        try {
            $items = $this->transferService->client($account)->accountLogs(date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 1));
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'message' => '查询账务明细成功，最近一小时有 ' . count($items) . ' 条流水'];
    }

    /**
     * 商户的收款配置：这个商户指定的账户，和全部账户（选的时候用）。
     *
     * @return array{alipay_account_id: null|int, accounts: list<array{id: int, name: string, account: string, status: string}>}
     */
    public function forMerchant(int $merchantId): array
    {
        if ($this->merchantDao->find($merchantId) === null) {
            throw new HttpException(404, '商户不存在');
        }

        return [
            'alipay_account_id' => $this->merchantDao->alipayAccountId($merchantId),
            'accounts' => $this->accountDao->newQuery()->orderBy('id')->get()
                ->map(static fn (AlipayAccount $a) => ['id' => $a->id, 'name' => $a->name, 'account' => $a->account, 'status' => $a->status])
                ->values()->all(),
        ];
    }

    /**
     * 指定商户用哪个账户，null 表示不能用支付宝转账加款。已发起的单不受影响。
     *
     * @return array<string, mixed> 同 forMerchant()
     */
    public function bindMerchant(AdminUser $operator, int $merchantId, mixed $accountId, ?string $ip): array
    {
        if ($this->merchantDao->find($merchantId) === null) {
            throw new HttpException(404, '商户不存在');
        }
        $accountId = is_numeric($accountId) && (int) $accountId > 0 ? (int) $accountId : null;
        if ($accountId !== null && $this->accountDao->find($accountId) === null) {
            throw new HttpException(422, '支付宝账户不存在');
        }
        $before = $this->merchantDao->alipayAccountId($merchantId);
        if ($before !== $accountId) {
            $this->merchantDao->setAlipayAccountId($merchantId, $accountId);
            $this->operationLogDao->record($operator->id, 'funding', 'bind_merchant_alipay_account', 'merchant', $merchantId, ['alipay_account_id' => $before], ['alipay_account_id' => $accountId], $ip);
        }

        return $this->forMerchant($merchantId);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?AlipayAccount $existing): array
    {
        $privateKey = trim(is_string($data['private_key'] ?? null) ? $data['private_key'] : '');
        if ($privateKey === '' && $existing === null) {
            throw new HttpException(422, '请填写应用私钥');
        }
        if ($privateKey !== '' && ! AlipayClient::isPrivateKey($privateKey)) {
            throw new HttpException(422, '应用私钥格式不对，请粘贴支付宝密钥工具生成的应用私钥');
        }
        $appId = $this->requiredString($data['app_id'] ?? null, 'AppID', 32);
        if (preg_match('/^\d{16}$/', $appId) !== 1) {
            throw new HttpException(422, 'AppID 是 16 位数字');
        }
        $publicKey = trim(is_string($data['alipay_public_key'] ?? null) ? $data['alipay_public_key'] : '');
        if (! AlipayClient::isPublicKey($publicKey)) {
            throw new HttpException(422, '支付宝公钥格式不对（在开放平台「接口加签方式」里复制支付宝公钥，不是应用公钥）');
        }
        $account = $this->requiredString($data['account'] ?? null, '收款支付宝账号', 64);
        if (preg_match('/^1\d{10}$/', $account) !== 1 && filter_var($account, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(422, '收款支付宝账号填手机号或邮箱');
        }
        $qrCode = trim(is_string($data['qr_code'] ?? null) ? $data['qr_code'] : '');
        if ($qrCode === '' && $existing?->qr_code === null) {
            throw new HttpException(422, '请上传收款码');
        }
        if ($qrCode !== '' && $qrCode !== $existing?->qr_code && ! $this->storage->isUnder($qrCode, self::QR_CODE_DIR)) {
            throw new HttpException(422, '收款码图片不对，请重新上传');
        }
        $min = $this->money($data['min_amount'] ?? null, '单笔最低金额');
        $max = $this->money($data['max_amount'] ?? null, '单笔最高金额');
        if (bccomp($min, $max, 2) > 0) {
            throw new HttpException(422, '单笔最低金额不能大于最高金额');
        }
        $attrs = [
            'name' => $this->requiredString($data['name'] ?? null, '账户名称', 64),
            'app_id' => $appId,
            'alipay_public_key' => $publicKey,
            'account' => $account,
            'account_name' => $this->requiredString($data['account_name'] ?? null, '收款人姓名', 64),
            'min_amount' => $min,
            'max_amount' => $max,
            'remark' => $this->remark($data['remark'] ?? null),
        ];
        if ($privateKey !== '') {
            $attrs['private_key'] = $this->encrypter->encrypt($privateKey);
        }
        if ($qrCode !== '') {
            $attrs['qr_code'] = $qrCode;
        }

        return $attrs;
    }

    private function findOrFail(int $id): AlipayAccount
    {
        $account = $this->accountDao->find($id);
        if ($account === null) {
            throw new HttpException(404, '支付宝账户不存在');
        }

        return $account;
    }

    /**
     * 收款码的临时预览链接，存储没配好时为 null。
     */
    private function qrCodeUrl(AlipayAccount $a): ?string
    {
        if ($a->qr_code === null) {
            return null;
        }
        try {
            return $this->storage->url($a->qr_code);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 不返回私钥（也用来记操作日志，所以不带每次都变的预览链接）。
     *
     * @return array<string, mixed>
     */
    private function format(AlipayAccount $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'app_id' => $a->app_id,
            'alipay_public_key' => $a->alipay_public_key,
            'account' => $a->account,
            'account_name' => $a->account_name,
            'qr_code' => $a->qr_code,
            'min_amount' => $a->min_amount,
            'max_amount' => $a->max_amount,
            'status' => $a->status,
            'remark' => $a->remark,
            'created_at' => $a->created_at?->toDateTimeString(),
            'updated_at' => $a->updated_at?->toDateTimeString(),
        ];
    }
}
