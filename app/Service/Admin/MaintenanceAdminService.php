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
use App\Dao\ChannelMaintenanceDao;
use App\Dao\SupplierDao;
use App\Enum\Operator;
use App\Enum\Province;
use App\Model\AdminUser;
use App\Model\ChannelMaintenance;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 风控 - 通道维护：按「供应商」或「供应商 + 运营商 + 省份」临时关闭，到结束时间自动恢复。
 *
 * 维护中的通道不参与选路（见 ProductRouteDao::candidates()），已经提交出去的订单不受影响。
 */
class MaintenanceAdminService extends AbstractService
{
    private const MODULE = 'risk';

    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected ChannelMaintenanceDao $maintenanceDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @param array<string, mixed> $query supplier_id / state（active 维护中 / upcoming 未开始 / ended 已结束）
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));
        $now = date('Y-m-d H:i:s');

        $builder = $this->maintenanceDao->newQuery();
        if (isset($query['supplier_id']) && is_numeric($query['supplier_id'])) {
            $builder->where('supplier_id', (int) $query['supplier_id']);
        }
        match ($query['state'] ?? null) {
            'active' => $builder->where('start_at', '<=', $now)->where('end_at', '>', $now),
            'upcoming' => $builder->where('start_at', '>', $now),
            'ended' => $builder->where('end_at', '<=', $now),
            default => null,
        };

        $total = (clone $builder)->count();
        $rows = $builder->orderByDesc('id')->forPage($page, $perPage)->get();
        $suppliers = $this->supplierDao->newQuery()->whereIn('id', $rows->pluck('supplier_id')->unique()->all())->pluck('name', 'id');
        $admins = $this->adminUserDao->newQuery()->whereIn('id', $rows->pluck('admin_user_id')->filter()->unique()->all())->pluck('real_name', 'id');

        return [
            'data' => $rows->map(fn (ChannelMaintenance $m) => $this->format($m, $suppliers[$m->supplier_id] ?? null) + [
                'admin_name' => $m->admin_user_id ? ($admins[$m->admin_user_id] ?? null) : null,
            ])->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $data supplier_id / operator（可空）/ province（可空）/ start_at（可空，默认现在）/ end_at / reason
     * @return array<string, mixed>
     */
    public function create(AdminUser $operator, array $data, ?string $ip): array
    {
        $supplier = is_numeric($data['supplier_id'] ?? null) ? $this->supplierDao->find((int) $data['supplier_id']) : null;
        if ($supplier === null) {
            throw new HttpException(422, '请选择供应商');
        }
        $op = $data['operator'] ?? null;
        if ($op !== null && $op !== '' && Operator::tryFrom((string) $op) === null) {
            throw new HttpException(422, '未知的运营商');
        }
        $province = $data['province'] ?? null;
        if ($province !== null && $province !== '' && ! Province::isValid((string) $province)) {
            throw new HttpException(422, '未知的省份');
        }
        $start = $this->dateTime($data['start_at'] ?? null, '开始时间') ?? date('Y-m-d H:i:s');
        $end = $this->dateTime($data['end_at'] ?? null, '结束时间');
        if ($end === null) {
            throw new HttpException(422, '请填写结束时间，到时自动恢复');
        }
        if ($end <= $start || $end <= date('Y-m-d H:i:s')) {
            throw new HttpException(422, '结束时间必须晚于开始时间和现在');
        }
        $reason = trim(is_string($data['reason'] ?? null) ? $data['reason'] : '');

        $row = $this->maintenanceDao->create([
            'supplier_id' => $supplier->id,
            'operator' => $op === '' ? null : $op,
            'province' => $province === '' ? null : $province,
            'start_at' => $start,
            'end_at' => $end,
            'reason' => $reason === '' ? null : mb_substr($reason, 0, 255),
            'admin_user_id' => $operator->id,
        ]);
        $after = $this->format($row, $supplier->name);
        $this->operationLogDao->record($operator->id, self::MODULE, 'create_maintenance', 'channel_maintenance', $row->id, null, $after, $ip);

        return $after;
    }

    /**
     * 提前结束：结束时间改成现在。
     *
     * @return array<string, mixed>
     */
    public function finish(AdminUser $operator, int $id, ?string $ip): array
    {
        $row = $this->findOrFail($id);
        $now = date('Y-m-d H:i:s');
        if ($row->end_at->toDateTimeString() <= $now) {
            throw new HttpException(422, '这条维护已经结束了');
        }
        $before = ['end_at' => $row->end_at->toDateTimeString()];
        $attrs = ['end_at' => $now];
        if ($row->start_at->toDateTimeString() > $now) {
            // 还没开始的直接作废
            $attrs['start_at'] = $now;
        }
        $this->maintenanceDao->update($row->id, $attrs);
        $this->operationLogDao->record($operator->id, self::MODULE, 'finish_maintenance', 'channel_maintenance', $row->id, $before, ['end_at' => $now], $ip);

        return $this->format($this->findOrFail($row->id), $this->supplierDao->find($row->supplier_id)?->name);
    }

    private function findOrFail(int $id): ChannelMaintenance
    {
        $row = $this->maintenanceDao->find($id);
        if (! $row) {
            throw new HttpException(404, '维护记录不存在');
        }

        return $row;
    }

    private function dateTime(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $time = is_string($value) ? strtotime($value) : false;
        if ($time === false) {
            throw new HttpException(422, "{$label}格式不对，例如 2026-10-08 02:00:00");
        }

        return date('Y-m-d H:i:s', $time);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(ChannelMaintenance $m, ?string $supplierName): array
    {
        $now = date('Y-m-d H:i:s');
        $start = $m->start_at->toDateTimeString();
        $end = $m->end_at->toDateTimeString();

        return [
            'id' => $m->id,
            'supplier_id' => $m->supplier_id,
            'supplier_name' => $supplierName,
            'operator' => $m->operator,
            'province' => $m->province,
            'start_at' => $start,
            'end_at' => $end,
            'state' => $end <= $now ? 'ended' : ($start > $now ? 'upcoming' : 'active'),
            'reason' => $m->reason,
            'created_at' => $m->created_at?->toDateTimeString(),
        ];
    }
}
