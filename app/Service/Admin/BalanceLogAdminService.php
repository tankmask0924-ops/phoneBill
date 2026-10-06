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

use App\Dao\AdminUserDao;
use App\Dao\MerchantBalanceLogDao;
use App\Dao\MerchantDao;
use App\Dao\OrderDao;
use App\Model\MerchantBalanceLog;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 商户中心 - 资金流水，只读。
 */
class BalanceLogAdminService extends AbstractService
{
    private const MAX_PER_PAGE = 100;

    private const TYPES = [
        MerchantBalanceLog::TYPE_RECHARGE,
        MerchantBalanceLog::TYPE_DEDUCT,
        MerchantBalanceLog::TYPE_ORDER_PAY,
        MerchantBalanceLog::TYPE_ORDER_REFUND,
    ];

    #[Inject]
    protected MerchantBalanceLogDao $balanceLogDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    #[Inject]
    protected OrderDao $orderDao;

    /**
     * @param array<string, mixed> $query merchant_id / type / order_id / order_no / created_from / created_to
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));

        $builder = $this->balanceLogDao->newQuery();
        if (is_string($query['order_no'] ?? null) && $query['order_no'] !== '') {
            $builder->where('order_id', $this->orderDao->findByOrderNo($query['order_no'])?->id ?? 0);
        }
        foreach (['merchant_id', 'order_id'] as $field) {
            if (isset($query[$field]) && is_numeric($query[$field])) {
                $builder->where($field, (int) $query[$field]);
            }
        }
        if (in_array($query['type'] ?? null, self::TYPES, true)) {
            $builder->where('type', $query['type']);
        }
        foreach (['created_from' => ['>=', ' 00:00:00'], 'created_to' => ['<=', ' 23:59:59']] as $field => [$op, $time]) {
            if (is_string($query[$field] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $query[$field])) {
                $builder->where('created_at', $op, $query[$field] . $time);
            }
        }

        $total = (clone $builder)->count();
        $logs = $builder->orderByDesc('id')->forPage($page, $perPage)->get();
        $merchants = $this->merchantDao->newQuery()->whereIn('id', $logs->pluck('merchant_id')->unique()->all())->pluck('name', 'id');
        $admins = $this->adminUserDao->newQuery()->whereIn('id', $logs->pluck('admin_user_id')->filter()->unique()->all())->pluck('real_name', 'id');
        $orders = $this->orderDao->newQuery()->whereIn('id', $logs->pluck('order_id')->filter()->unique()->all())->pluck('order_no', 'id');

        return [
            'data' => $logs->map(static fn (MerchantBalanceLog $log) => [
                'id' => $log->id,
                'merchant_id' => $log->merchant_id,
                'merchant_name' => $merchants[$log->merchant_id] ?? null,
                'type' => $log->type,
                'amount' => $log->amount,
                'balance_after' => $log->balance_after,
                'order_id' => $log->order_id,
                'order_no' => $log->order_id ? ($orders[$log->order_id] ?? null) : null,
                'remark' => $log->remark,
                'admin_user_id' => $log->admin_user_id,
                'admin_name' => $log->admin_user_id ? ($admins[$log->admin_user_id] ?? null) : null,
                'created_at' => $log->created_at?->toDateTimeString(),
            ])->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
