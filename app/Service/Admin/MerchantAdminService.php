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
use App\Dao\MerchantProductDao;
use App\Exception\InsufficientBalanceException;
use App\Model\AdminUser;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Security\Encrypter;
use App\Service\AbstractService;
use App\Service\Merchant\MerchantAccessService;
use App\Service\Merchant\MerchantBalanceService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户中心 - 商户：资料、开放接口密钥、IP 白名单、通知地址、启停、余额加减款。
 *
 * - AppKey 建好后不变；AppSecret 加密存储，只在新建和重置时返回一次明文，之后谁都看不到；
 * - 加减款必须填备注，每次都写资金流水并记录操作人，扣款不能扣成负数；
 * - 不提供删除，不合作了就停用。
 */
class MerchantAdminService extends AbstractService
{
    use ValidatesAdminInput;

    private const MODULE = 'merchant';

    /** 授信额度上限（元） */
    private const MAX_CREDIT_LIMIT = '10000000';

    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantProductDao $merchantProductDao;

    #[Inject]
    protected MerchantBalanceService $balanceService;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    #[Inject]
    protected Encrypter $encrypter;

    #[Inject]
    protected MerchantAccessService $accessService;

    /**
     * @param array<string, mixed> $query keyword / status
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 15)));

        $builder = $this->merchantDao->newQuery();
        $keyword = trim((string) ($query['keyword'] ?? ''));
        if ($keyword !== '') {
            $builder->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")->orWhere('app_key', $keyword));
        }
        if (in_array($query['status'] ?? null, ['active', 'disabled'], true)) {
            $builder->where('status', $query['status']);
        }

        $total = (clone $builder)->count();
        $merchants = $builder->orderByDesc('id')->forPage($page, $perPage)->get();
        $productCounts = $this->merchantProductDao->newQuery()
            ->whereIn('merchant_id', $merchants->pluck('id')->all())
            ->where('status', 'active')
            ->selectRaw('merchant_id, count(*) as n')
            ->groupBy('merchant_id')
            ->pluck('n', 'merchant_id');

        return [
            'data' => $merchants->map(fn (Merchant $m) => $this->format($m) + [
                'product_count' => (int) ($productCounts[$m->id] ?? 0),
            ])->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 下拉框用。
     *
     * @return list<array{id: int, name: string, status: string}>
     */
    public function options(): array
    {
        return $this->merchantDao->newQuery()->orderBy('id')->get(['id', 'name', 'status'])
            ->map(static fn (Merchant $m) => ['id' => $m->id, 'name' => $m->name, 'status' => $m->status])
            ->values()->all();
    }

    /**
     * @param array<string, mixed> $data name / contact / phone / notify_url / ip_whitelist / remark
     * @return array<string, mixed> 带 app_secret 明文，只返回这一次
     */
    public function create(AdminUser $operator, array $data, ?string $ip): array
    {
        $attrs = $this->validateProfile($data, null);
        $secret = $this->accessService->newSecret();
        $merchant = $this->merchantDao->create($attrs + [
            'app_key' => bin2hex(random_bytes(16)),
            'app_secret' => $this->encrypter->encrypt($secret),
            'balance' => '0.00',
            'status' => 'active',
        ]);
        $after = $this->format($merchant);
        $this->operationLogDao->record($operator->id, self::MODULE, 'create_merchant', 'merchant', $merchant->id, null, $after, $ip);

        return $after + ['app_secret' => $secret];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(AdminUser $operator, int $id, array $data, ?string $ip): array
    {
        $merchant = $this->findOrFail($id);
        $before = $this->format($merchant);
        $attrs = $this->validateProfile($data, $merchant);
        if ($attrs !== []) {
            $this->merchantDao->update($merchant->id, $attrs);
        }
        $after = $this->format($this->findOrFail($merchant->id));
        $this->operationLogDao->record($operator->id, self::MODULE, 'update_merchant', 'merchant', $merchant->id, $before, $after, $ip);

        return $after;
    }

    /**
     * 停用后开放接口一律拒绝，已经在充值中的订单照常处理完。
     *
     * @return array<string, mixed>
     */
    public function changeStatus(AdminUser $operator, int $id, mixed $status, ?string $ip): array
    {
        $status = $this->status($status);
        $merchant = $this->findOrFail($id);
        if ($status !== $merchant->status) {
            $this->merchantDao->update($merchant->id, ['status' => $status]);
            $this->operationLogDao->record(
                $operator->id,
                self::MODULE,
                $status === 'active' ? 'enable_merchant' : 'disable_merchant',
                'merchant',
                $merchant->id,
                ['status' => $merchant->status],
                ['status' => $status],
                $ip
            );
        }

        return $this->format($this->findOrFail($merchant->id));
    }

    /**
     * 重置签名密钥，旧密钥立即失效。新密钥只返回这一次。
     *
     * @return array{app_key: string, app_secret: string}
     */
    public function resetSecret(AdminUser $operator, int $id, ?string $ip): array
    {
        $merchant = $this->findOrFail($id);
        $secret = $this->accessService->newSecret();
        $this->merchantDao->update($merchant->id, ['app_secret' => $this->encrypter->encrypt($secret)]);
        // 不记密钥本身
        $this->operationLogDao->record($operator->id, self::MODULE, 'reset_merchant_secret', 'merchant', $merchant->id, null, ['name' => $merchant->name], $ip);

        return ['app_key' => $merchant->app_key, 'app_secret' => $secret];
    }

    /**
     * 人工加款 / 扣款。
     *
     * @param array<string, mixed> $data type（recharge / deduct）/ amount / remark
     * @return array<string, mixed>
     */
    public function adjustBalance(AdminUser $operator, int $id, array $data, ?string $ip): array
    {
        $merchant = $this->findOrFail($id);
        $type = $data['type'] ?? null;
        if (! in_array($type, [MerchantBalanceLog::TYPE_RECHARGE, MerchantBalanceLog::TYPE_DEDUCT], true)) {
            throw new HttpException(422, 'type 只能是 recharge（加款）或 deduct（扣款）');
        }
        $amount = $this->money($data['amount'] ?? null, '金额');
        $remark = $this->requiredString($data['remark'] ?? null, '备注', 255);
        $signed = $type === MerchantBalanceLog::TYPE_RECHARGE ? $amount : bcmul($amount, '-1', 2);

        try {
            $log = Db::transaction(fn () => $this->balanceService->change($merchant->id, $type, $signed, null, $remark, $operator->id));
        } catch (InsufficientBalanceException $e) {
            throw new HttpException(422, bccomp($e->creditLimit, '0', 2) > 0
                ? "扣款后会超出授信额度（当前余额 {$e->balance} 元，授信 {$e->creditLimit} 元）"
                : "扣款后余额会变成负数（当前余额 {$e->balance} 元）");
        }
        $this->operationLogDao->record(
            $operator->id,
            self::MODULE,
            $type === MerchantBalanceLog::TYPE_RECHARGE ? 'recharge_merchant' : 'deduct_merchant',
            'merchant',
            $merchant->id,
            ['balance' => $merchant->balance],
            ['balance' => $log->balance_after, 'amount' => $signed, 'remark' => $remark],
            $ip
        );

        return $this->format($this->findOrFail($merchant->id));
    }

    /**
     * 设置授信额度：余额最低可以扣到它的负数，0 表示不能负数。调低到比当前欠款还少也允许，已有欠款不动，
     * 只是还到授信线以上之前不能再下单。授信不是钱，不写资金流水，只记操作日志。
     *
     * @return array<string, mixed>
     */
    public function setCreditLimit(AdminUser $operator, int $id, mixed $creditLimit, ?string $ip): array
    {
        $merchant = $this->findOrFail($id);
        $value = is_string($creditLimit) || is_int($creditLimit) || is_float($creditLimit) ? trim((string) $creditLimit) : '';
        if (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $value) !== 1 || bccomp($value, self::MAX_CREDIT_LIMIT, 2) > 0) {
            throw new HttpException(422, '授信额度是 0 ~ ' . self::MAX_CREDIT_LIMIT . ' 的金额，最多两位小数');
        }
        $value = bcadd($value, '0', 2);
        $before = (string) $merchant->credit_limit;
        if (bccomp($before, $value, 2) !== 0) {
            $this->merchantDao->update($merchant->id, ['credit_limit' => $value]);
            $this->operationLogDao->record($operator->id, self::MODULE, 'set_merchant_credit_limit', 'merchant', $merchant->id, ['credit_limit' => $before], ['credit_limit' => $value, 'balance' => $merchant->balance], $ip);
        }

        return $this->format($this->findOrFail($merchant->id));
    }

    public function findOrFail(int $id): Merchant
    {
        $merchant = $this->merchantDao->find($id);
        if (! $merchant) {
            throw new HttpException(404, '商户不存在');
        }

        return $merchant;
    }

    /**
     * 新建时全部字段都校验；编辑时只校验传了的字段。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validateProfile(array $data, ?Merchant $merchant): array
    {
        $attrs = [];
        if ($merchant === null || array_key_exists('name', $data)) {
            $name = $this->requiredString($data['name'] ?? null, '商户名称', 64);
            $exists = $this->merchantDao->newQuery()
                ->where('name', $name)
                ->when($merchant !== null, fn ($q) => $q->where('id', '!=', $merchant->id))
                ->exists();
            if ($exists) {
                throw new HttpException(422, "商户「{$name}」已存在");
            }
            $attrs['name'] = $name;
        }
        foreach (['contact' => 32, 'phone' => 32] as $field => $max) {
            if ($merchant === null || array_key_exists($field, $data)) {
                $value = trim(is_string($data[$field] ?? null) ? $data[$field] : '');
                $attrs[$field] = $value === '' ? null : mb_substr($value, 0, $max);
            }
        }
        if ($merchant === null || array_key_exists('notify_url', $data)) {
            $attrs['notify_url'] = $this->notifyUrl($data['notify_url'] ?? null);
        }
        if ($merchant === null || array_key_exists('ip_whitelist', $data)) {
            $attrs['ip_whitelist'] = $this->accessService->ipWhitelist($data['ip_whitelist'] ?? null);
        }
        if ($merchant === null || array_key_exists('remark', $data)) {
            $attrs['remark'] = $this->remark($data['remark'] ?? null);
        }
        // 下单限流：每 N 秒最多 M 单，任一为 0 不限
        foreach (['rate_limit_seconds' => ['限流时间窗口（秒）', 86400], 'rate_limit_orders' => ['限流单数', 100000]] as $field => [$label, $max]) {
            if ($merchant === null || array_key_exists($field, $data)) {
                $value = $data[$field] ?? 0;
                if ($value === null || $value === '') {
                    $value = 0;
                }
                if (! is_numeric($value) || (int) $value != $value || $value < 0 || $value > $max) {
                    throw new HttpException(422, "{$label}必须是 0~{$max} 的整数，0 表示不限");
                }
                $attrs[$field] = (int) $value;
            }
        }

        return $attrs;
    }

    private function notifyUrl(mixed $url): ?string
    {
        $url = trim(is_string($url) ? $url : '');
        if ($url === '') {
            return null;
        }
        if (mb_strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#i', $url)) {
            throw new HttpException(422, '通知地址必须是 http:// 或 https:// 开头的网址');
        }

        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(Merchant $merchant): array
    {
        return [
            'id' => $merchant->id,
            'name' => $merchant->name,
            'contact' => $merchant->contact,
            'phone' => $merchant->phone,
            'app_key' => $merchant->app_key,
            'balance' => $merchant->balance,
            'credit_limit' => $merchant->credit_limit,
            'rate_limit_seconds' => $merchant->rate_limit_seconds ?? 0,
            'rate_limit_orders' => $merchant->rate_limit_orders ?? 0,
            'available_balance' => $merchant->availableBalance(),
            'notify_url' => $merchant->notify_url,
            'ip_whitelist' => $merchant->ipWhitelist(),
            'status' => $merchant->status,
            'remark' => $merchant->remark,
            'created_at' => $merchant->created_at?->toDateTimeString(),
            'updated_at' => $merchant->updated_at?->toDateTimeString(),
        ];
    }
}
