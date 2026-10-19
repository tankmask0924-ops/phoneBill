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
use App\Dao\OrderAttemptDao;
use App\Dao\SupplierDao;
use App\Dao\SupplierProductDao;
use App\Enum\Province;
use App\Model\AdminUser;
use App\Model\Supplier;
use App\Security\Encrypter;
use App\Service\AbstractService;
use App\Service\Product\ProductRouteService;
use App\Service\Supplier\SupplierGateway;
use App\Supplier\SupplierDriverInterface;
use App\Supplier\SupplierDriverRegistry;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商品中心 - 供应商：名称、编码、对接驱动与接口参数、启用停用（覆盖省份在供应商商品上）。
 *
 * - 编码用在供应商回调地址里，建好后不能改；回调地址按系统配置里的回调域名自动生成（见 SupplierGateway::notifyUrl()），不用填；
 * - 接口参数整体加密存储；密钥类参数（驱动声明为 secret）接口不回显，编辑时留空表示不修改；
 * - 删除是软删除：名下没有供应商商品、也没有在途的单才能删；删除后历史订单照常显示名字，名称和编码不能再用。
 */
class SupplierAdminService extends AbstractService
{
    use ValidatesAdminInput;

    private const MODULE = 'product';

    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierProductDao $supplierProductDao;

    #[Inject]
    protected OrderAttemptDao $attemptDao;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    #[Inject]
    protected ProductRouteService $routeService;

    #[Inject]
    protected SupplierDriverRegistry $driverRegistry;

    #[Inject]
    protected Encrypter $encrypter;

    #[Inject]
    protected SupplierGateway $supplierGateway;

    /**
     * 表单要用的选项：可选的对接驱动（含参数定义）、省份（给供应商商品用）。
     *
     * @return array{drivers: list<array<string, mixed>>, provinces: list<string>}
     */
    public function meta(): array
    {
        return [
            'drivers' => array_map(static fn (SupplierDriverInterface $d) => [
                'code' => $d->code(),
                'name' => $d->name(),
                'config_schema' => $d->configSchema(),
            ], $this->driverRegistry->all()),
            'provinces' => Province::NAMES,
        ];
    }

    /**
     * @param array<string, mixed> $query keyword / status
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 15)));

        $builder = $this->supplierDao->newQuery();
        $keyword = trim((string) ($query['keyword'] ?? ''));
        if ($keyword !== '') {
            $builder->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%"));
        }
        if (in_array($query['status'] ?? null, ['active', 'disabled'], true)) {
            $builder->where('status', $query['status']);
        }

        $total = (clone $builder)->count();
        $suppliers = $builder->orderBy('id')->forPage($page, $perPage)->get();
        $ids = $suppliers->pluck('id')->all();
        $productCounts = $this->supplierProductDao->newQuery()
            ->whereIn('supplier_id', $ids)
            ->selectRaw('supplier_id, count(*) as n')
            ->groupBy('supplier_id')
            ->pluck('n', 'supplier_id');

        return [
            'data' => $suppliers->map(fn (Supplier $s) => $this->format($s) + [
                'product_count' => (int) ($productCounts[$s->id] ?? 0),
            ])->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 给供应商商品页的下拉框用。
     *
     * @return list<array{id: int, name: string, status: string}>
     */
    public function options(): array
    {
        return $this->supplierDao->newQuery()->orderBy('id')->get(['id', 'name', 'status', 'driver'])
            ->map(fn (Supplier $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'status' => $s->status,
                'supports_upstream_products' => $this->supplierGateway->supportsUpstreamProducts($s),
            ])
            ->values()->all();
    }

    /**
     * @param array<string, mixed> $data name / code / driver / config / remark
     * @return array<string, mixed>
     */
    public function create(AdminUser $operator, array $data, ?string $ip): array
    {
        $name = $this->validateName($data['name'] ?? null, null);
        $code = (string) ($data['code'] ?? '');
        if (preg_match('/^[a-z0-9_]{2,32}$/', $code) !== 1) {
            throw new HttpException(422, '编码为 2~32 位小写字母、数字或下划线');
        }
        $sameCode = $this->supplierDao->findByCodeWithTrashed($code);
        if ($sameCode !== null) {
            throw new HttpException(422, $sameCode->trashed() ? "编码「{$code}」被已删除的供应商用过，请换一个" : "编码「{$code}」已存在");
        }
        $driver = $this->driver($data['driver'] ?? null);
        $config = $this->validateConfig($driver, $data['config'] ?? [], []);

        return Db::transaction(function () use ($operator, $name, $code, $driver, $config, $data, $ip) {
            $supplier = $this->supplierDao->create([
                'name' => $name,
                'code' => $code,
                'driver' => $driver->code(),
                'config' => $this->encrypter->encrypt(json_encode($config, JSON_UNESCAPED_UNICODE)),
                'status' => 'active',
                'remark' => $this->remark($data['remark'] ?? null),
            ]);
            $after = $this->format($supplier);
            $this->operationLogDao->record($operator->id, self::MODULE, 'create_supplier', 'supplier', $supplier->id, null, $after, $ip);

            return $after;
        });
    }

    /**
     * 编码不能改；接口参数里的密钥留空表示不修改。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(AdminUser $operator, int $id, array $data, ?string $ip): array
    {
        $supplier = $this->findOrFail($id);
        if (array_key_exists('code', $data) && $data['code'] !== $supplier->code) {
            throw new HttpException(422, '供应商编码建好后不能修改');
        }

        $result = Db::transaction(function () use ($operator, $supplier, $data, $ip) {
            $before = $this->format($supplier);
            $attrs = [];

            if (array_key_exists('name', $data)) {
                $attrs['name'] = $this->validateName($data['name'], $supplier->id);
            }
            if (array_key_exists('driver', $data) || array_key_exists('config', $data)) {
                $driver = $this->driver($data['driver'] ?? $supplier->driver);
                // 换了驱动，旧参数（包括密钥）一律不沿用
                $existing = $driver->code() === $supplier->driver ? $this->decryptConfig($supplier) : [];
                $config = $this->validateConfig($driver, $data['config'] ?? [], $existing);
                $attrs['driver'] = $driver->code();
                $attrs['config'] = $this->encrypter->encrypt(json_encode($config, JSON_UNESCAPED_UNICODE));
            }
            if (array_key_exists('remark', $data)) {
                $attrs['remark'] = $this->remark($data['remark']);
            }
            if ($attrs !== []) {
                $this->supplierDao->update($supplier->id, $attrs);
            }

            $supplier = $this->findOrFail($supplier->id);
            $after = $this->format($supplier);
            $this->operationLogDao->record($operator->id, self::MODULE, 'update_supplier', 'supplier', $supplier->id, $before, $after, $ip);

            return $after;
        });
        // 驱动、参数可能变了，事务提交后清选路缓存和余额缓存
        $this->routeService->flushConfig();
        $this->supplierGateway->forgetBalance($supplier->id);

        return $result;
    }

    /**
     * 停用后不再参与选路，历史数据照常可查。
     *
     * @return array<string, mixed>
     */
    public function changeStatus(AdminUser $operator, int $id, mixed $status, ?string $ip): array
    {
        $status = $this->status($status);
        $supplier = $this->findOrFail($id);
        if ($status !== $supplier->status) {
            $this->supplierDao->update($supplier->id, ['status' => $status]);
            $this->operationLogDao->record(
                $operator->id,
                self::MODULE,
                $status === 'active' ? 'enable_supplier' : 'disable_supplier',
                'supplier',
                $supplier->id,
                ['status' => $supplier->status],
                ['status' => $status],
                $ip
            );
            $this->routeService->flushConfig();
            $supplier = $this->findOrFail($supplier->id);
        }

        return $this->format($supplier);
    }

    /**
     * 软删除。名下还有供应商商品（先删商品）或者有提交了还没出结果的单时不能删。
     */
    public function delete(AdminUser $operator, int $id, ?string $ip): void
    {
        $supplier = $this->findOrFail($id);
        $productCount = $this->supplierProductDao->countForSupplier($supplier->id);
        if ($productCount > 0) {
            throw new HttpException(422, "「{$supplier->name}」下还有 {$productCount} 个供应商商品，请先删除它们");
        }
        if ($this->attemptDao->hasProcessingForSupplier($supplier->id)) {
            throw new HttpException(422, "「{$supplier->name}」还有充值中的订单，等出结果后再删除");
        }
        $before = $this->format($supplier);
        $this->supplierDao->delete($supplier->id);
        $this->operationLogDao->record($operator->id, self::MODULE, 'delete_supplier', 'supplier', $supplier->id, $before, null, $ip);
        $this->routeService->flushConfig();
    }

    /**
     * 实时查供应商那边的余额（列表打开时逐个查），见 SupplierGateway::balance()。
     *
     * @return array{supported: bool, balance: null|string, error: null|string}
     */
    public function balance(int $id): array
    {
        return $this->supplierGateway->balance($this->findOrFail($id));
    }

    /**
     * 解密后的完整接口参数（含密钥），只给对接供应商时用，不要返回给前端。
     *
     * @return array<string, string>
     */
    public function decryptConfig(Supplier $supplier): array
    {
        return $this->supplierGateway->decryptConfig($supplier);
    }

    private function findOrFail(int $id): Supplier
    {
        $supplier = $this->supplierDao->find($id);
        if (! $supplier) {
            throw new HttpException(404, '供应商不存在');
        }

        return $supplier;
    }

    private function validateName(mixed $name, ?int $exceptId): string
    {
        $name = $this->requiredString($name, '供应商名称', 64);
        // 已删除的也占着名称（唯一索引）
        $same = $this->supplierDao->newQuery()->withTrashed()
            ->where('name', $name)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->first();
        if ($same !== null) {
            throw new HttpException(422, $same->trashed() ? "供应商「{$name}」和已删除的供应商重名，请换一个" : "供应商「{$name}」已存在");
        }

        return $name;
    }

    private function driver(mixed $code): SupplierDriverInterface
    {
        $driver = is_string($code) ? $this->driverRegistry->find($code) : null;
        if ($driver === null) {
            throw new HttpException(422, '请选择有效的对接驱动');
        }

        return $driver;
    }

    /**
     * 按驱动声明的参数逐个校验，多余的参数丢掉。
     *
     * @param array<string, string> $existing 已保存的参数，密钥留空时沿用
     * @return array<string, string>
     */
    private function validateConfig(SupplierDriverInterface $driver, mixed $input, array $existing): array
    {
        if (! is_array($input)) {
            throw new HttpException(422, 'config 必须是对象');
        }
        $config = [];
        foreach ($driver->configSchema() as $field) {
            $raw = $input[$field['key']] ?? null;
            $value = is_string($raw) || is_numeric($raw) ? trim((string) $raw) : '';
            if ($field['type'] === 'secret' && $value === '') {
                $value = $existing[$field['key']] ?? '';
            }
            if ($field['type'] === 'select' && $value !== '' && ! in_array($value, array_column($field['options'] ?? [], 'value'), true)) {
                throw new HttpException(422, "{$field['label']}的取值不对");
            }
            if ($field['required'] && $value === '') {
                throw new HttpException(422, "请填写{$field['label']}");
            }
            $config[$field['key']] = mb_substr($value, 0, 1000);
        }
        // 以前手填的回调地址留着，没配回调域名时还用它（见 SupplierGateway::driverConfig()）
        if (($existing['notify_url'] ?? '') !== '') {
            $config['notify_url'] = $existing['notify_url'];
        }

        return $config;
    }

    /**
     * 密钥类参数不回显，只在 configured_secrets 里告诉前端哪些已经填过。
     *
     * @return array<string, mixed>
     */
    private function format(Supplier $supplier): array
    {
        $driver = $this->driverRegistry->find($supplier->driver);
        $config = $this->decryptConfig($supplier);
        $visible = [];
        $configuredSecrets = [];
        foreach ($driver?->configSchema() ?? [] as $field) {
            $value = $config[$field['key']] ?? '';
            if ($field['type'] === 'secret') {
                if ($value !== '') {
                    $configuredSecrets[] = $field['key'];
                }
            } else {
                $visible[$field['key']] = $value;
            }
        }

        $notifyUrl = $this->supplierGateway->notifyUrl($supplier);

        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'code' => $supplier->code,
            // 自动生成的回调地址；没配回调域名时是以前手填的（可能为空），notify_url_generated 为 false
            'notify_url' => $notifyUrl ?? ($config['notify_url'] ?? null),
            'notify_url_generated' => $notifyUrl !== null,
            'driver' => $supplier->driver,
            'driver_name' => $driver?->name() ?? $supplier->driver,
            'config' => $visible,
            'configured_secrets' => $configuredSecrets,
            'status' => $supplier->status,
            'remark' => $supplier->remark,
            'created_at' => $supplier->created_at?->toDateTimeString(),
            'updated_at' => $supplier->updated_at?->toDateTimeString(),
        ];
    }
}
