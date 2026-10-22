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
use App\Service\FiltersByDateRange;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Collection;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户中心 - 资金流水，只读，可按筛选条件导出。
 */
class BalanceLogAdminService extends AbstractService
{
    use FiltersByDateRange;

    private const MAX_PER_PAGE = 100;

    private const MAX_EXPORT = 100000;

    private const EXPORT_CHUNK = 1000;

    private const TYPE_LABELS = [
        MerchantBalanceLog::TYPE_RECHARGE => '加款',
        MerchantBalanceLog::TYPE_DEDUCT => '扣款',
        MerchantBalanceLog::TYPE_ORDER_PAY => '下单扣款',
        MerchantBalanceLog::TYPE_ORDER_REFUND => '失败退款',
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
     * @param array<string, mixed> $query 见 filtered()
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));
        $builder = $this->filtered($query);

        $total = (clone $builder)->count();

        return [
            'data' => $this->formatMany($builder->orderByDesc('id')->forPage($page, $perPage)->get()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 按列表的筛选条件导出 CSV（Excel 能直接打开），最多 MAX_EXPORT 行，分批查避免一次载入全部流水。
     *
     * @param array<string, mixed> $query 见 filtered()
     */
    public function exportCsv(array $query): string
    {
        $builder = $this->filtered($query);
        if ((clone $builder)->count() > self::MAX_EXPORT) {
            throw new HttpException(422, '一次最多导出 ' . self::MAX_EXPORT . ' 条，请选定商户或缩小时间范围');
        }

        $out = fopen('php://temp', 'r+');
        // 带 BOM，Excel 才认得 UTF-8 中文
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['时间', '商户', '类型', '金额', '变动后余额', '平台订单号', '手机号', '备注', '操作人'], ',', '"', '');
        $builder->chunkByIdDesc(self::EXPORT_CHUNK, function (Collection $logs) use ($out) {
            foreach ($this->formatMany($logs) as $l) {
                fputcsv($out, [
                    $l['created_at'], $l['merchant_name'] ?? '', self::TYPE_LABELS[$l['type']] ?? $l['type'], $l['amount'], $l['balance_after'],
                    // 长数字后面加制表符，免得 Excel 转成科学计数法
                    $l['order_no'] === null ? '' : $l['order_no'] . "\t", $l['mobile'] === null ? '' : $l['mobile'] . "\t", $l['remark'] ?? '',
                    $l['admin_name'] ?? ($l['admin_user_id'] ? '#' . $l['admin_user_id'] : '系统'),
                ], ',', '"', '');
            }
        });
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @param array<string, mixed> $query merchant_id / type / order_id / order_no / created_from / created_to
     */
    private function filtered(array $query): Builder
    {
        $builder = $this->balanceLogDao->newQuery();
        $exactSearch = (isset($query['order_id']) && is_numeric($query['order_id'])) || (is_string($query['order_no'] ?? null) && $query['order_no'] !== '');
        if (is_string($query['order_no'] ?? null) && $query['order_no'] !== '') {
            $builder->where('order_id', $this->orderDao->findByOrderNo($query['order_no'])?->id ?? 0);
        }
        foreach (['merchant_id', 'order_id'] as $field) {
            if (isset($query[$field]) && is_numeric($query[$field])) {
                $builder->where($field, (int) $query[$field]);
            }
        }
        if (in_array($query['type'] ?? null, array_keys(self::TYPE_LABELS), true)) {
            $builder->where('type', $query['type']);
        }
        $this->applyDateRange($builder, $query, $exactSearch);

        return $builder;
    }

    /**
     * @param Collection<int, MerchantBalanceLog> $logs
     * @return list<array<string, mixed>>
     */
    private function formatMany(Collection $logs): array
    {
        $merchants = $this->merchantDao->newQuery()->whereIn('id', $logs->pluck('merchant_id')->unique()->all())->pluck('name', 'id');
        $admins = $this->adminUserDao->newQuery()->whereIn('id', $logs->pluck('admin_user_id')->filter()->unique()->all())->pluck('real_name', 'id');
        $orders = $this->orderDao->newQuery()->whereIn('id', $logs->pluck('order_id')->filter()->unique()->all())->get(['id', 'order_no', 'mobile'])->keyBy('id');

        return $logs->map(static fn (MerchantBalanceLog $log) => [
            'id' => $log->id,
            'merchant_id' => $log->merchant_id,
            'merchant_name' => $merchants[$log->merchant_id] ?? null,
            'type' => $log->type,
            'amount' => $log->amount,
            'balance_after' => $log->balance_after,
            'order_id' => $log->order_id,
            'order_no' => $log->order_id ? $orders->get($log->order_id)?->order_no : null,
            'mobile' => $log->order_id ? $orders->get($log->order_id)?->mobile : null,
            'remark' => $log->remark,
            'admin_user_id' => $log->admin_user_id,
            'admin_name' => $log->admin_user_id ? ($admins[$log->admin_user_id] ?? null) : null,
            'created_at' => $log->created_at?->toDateTimeString(),
        ])->values()->all();
    }
}
