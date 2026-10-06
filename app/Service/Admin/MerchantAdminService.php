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
use App\Network\IpAddress;
use App\Security\Encrypter;
use App\Service\AbstractService;
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
        $secret = $this->newSecret();
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
        $secret = $this->newSecret();
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
            throw new HttpException(422, "扣款后余额会变成负数（当前余额 {$e->balance} 元）");
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

    public function findOrFail(int $id): Merchant
    {
        $merchant = $this->merchantDao->find($id);
        if (! $merchant) {
            throw new HttpException(404, '商户不存在');
        }

        return $merchant;
    }

    private function newSecret(): string
    {
        return bin2hex(random_bytes(32));
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
            $attrs['ip_whitelist'] = $this->ipWhitelist($data['ip_whitelist'] ?? null);
        }
        if ($merchant === null || array_key_exists('remark', $data)) {
            $attrs['remark'] = $this->remark($data['remark'] ?? null);
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
     * 支持数组或逗号 / 换行分隔的字符串，每项是 IP 或 CIDR。
     */
    private function ipWhitelist(mixed $value): ?string
    {
        $items = is_array($value) ? $value : preg_split('/[\s,，]+/u', is_string($value) ? $value : '');
        $result = [];
        foreach ($items as $item) {
            $item = trim(is_string($item) ? $item : '');
            if ($item === '') {
                continue;
            }
            if (! $this->isValidIpOrCidr($item)) {
                throw new HttpException(422, "IP 白名单里的「{$item}」不是合法的 IP 或网段");
            }
            $result[] = $item;
        }
        $joined = implode(',', array_values(array_unique($result)));
        if (mb_strlen($joined) > 1000) {
            throw new HttpException(422, 'IP 白名单太长，请合并成网段');
        }

        return $joined === '' ? null : $joined;
    }

    private function isValidIpOrCidr(string $item): bool
    {
        if (! str_contains($item, '/')) {
            return IpAddress::toBinary($item) !== null;
        }
        [$network, $prefix] = explode('/', $item, 2);
        $binary = IpAddress::toBinary($network);

        return $binary !== null && ctype_digit($prefix) && (int) $prefix <= strlen($binary) * 8;
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
            'notify_url' => $merchant->notify_url,
            'ip_whitelist' => $merchant->ipWhitelist(),
            'status' => $merchant->status,
            'remark' => $merchant->remark,
            'created_at' => $merchant->created_at?->toDateTimeString(),
            'updated_at' => $merchant->updated_at?->toDateTimeString(),
        ];
    }
}
