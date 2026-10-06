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
use App\Dao\MerchantBalanceLogDao;
use App\Dao\MerchantDao;
use App\Dao\OrderAttemptDao;
use App\Dao\OrderDao;
use App\Dao\OrderNotifyLogDao;
use App\Dao\SupplierDao;
use App\Dao\SupplierProductDao;
use App\Enum\Operator;
use App\Enum\Province;
use App\Model\AdminUser;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderNotifyLog;
use App\Service\AbstractService;
use App\Service\FiltersByDateRange;
use App\Service\Order\OrderDispatcher;
use App\Service\Order\OrderProcessService;
use Hyperf\Database\Model\Builder;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 订单中心：列表、详情、导出，以及人工处理（docs/project.md 4.7）。
 *
 * - 置成功 / 置失败只对异常订单；冲正只对成功订单，要单独权限；三者都必须写备注，都记操作日志；
 * - 置失败、冲正都会退款并重新通知商户。
 */
class OrderAdminService extends AbstractService
{
    use FiltersByDateRange;
    use ValidatesAdminInput;

    private const MODULE = 'order';

    private const MAX_PER_PAGE = 100;

    private const MAX_EXPORT = 10000;

    private const STATUSES = [Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL, Order::STATUS_SUCCESS, Order::STATUS_FAILED];

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderAttemptDao $attemptDao;

    #[Inject]
    protected OrderNotifyLogDao $notifyLogDao;

    #[Inject]
    protected MerchantBalanceLogDao $balanceLogDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierProductDao $supplierProductDao;

    #[Inject]
    protected OrderProcessService $processService;

    #[Inject]
    protected OrderDispatcher $dispatcher;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * 筛选下拉框。
     *
     * @return array<string, mixed>
     */
    public function filterOptions(): array
    {
        return [
            'merchants' => $this->merchantDao->newQuery()->orderBy('id')->get(['id', 'name'])->toArray(),
            'suppliers' => $this->supplierDao->newQuery()->orderBy('id')->get(['id', 'name'])->toArray(),
            'provinces' => Province::NAMES,
        ];
    }

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
        $orders = $builder->orderByDesc('id')->forPage($page, $perPage)->get()->all();

        return ['data' => $this->formatMany($orders), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * 导出 CSV（Excel 能直接打开），最多 MAX_EXPORT 行。
     *
     * @param array<string, mixed> $query
     */
    public function exportCsv(array $query): string
    {
        $builder = $this->filtered($query);
        if ((clone $builder)->count() > self::MAX_EXPORT) {
            throw new HttpException(422, '一次最多导出 ' . self::MAX_EXPORT . ' 条，请缩小时间范围');
        }
        $rows = $this->formatMany($builder->orderByDesc('id')->get()->all());

        $statusLabels = ['pending' => '充值中', 'processing' => '充值中', 'abnormal' => '异常', 'success' => '成功', 'failed' => '失败'];
        $lines = [['平台订单号', '商户订单号', '商户', '商品', '手机号', '运营商', '省份', '面值', '扣款金额', '成本', '供应商', '状态', '失败原因', '下单时间', '完成时间']];
        foreach ($rows as $r) {
            $lines[] = [
                $r['order_no'] . "\t", $r['merchant_order_no'] . "\t", $r['merchant_name'], $r['product_name'], $r['mobile'] . "\t",
                Operator::tryFrom($r['operator'])?->label() ?? $r['operator'], $r['province'], $r['face_value'], $r['sale_price'],
                $r['cost_price'] ?? '', $r['supplier_name'] ?? '', $statusLabels[$r['status']] ?? $r['status'], $r['fail_reason'] ?? '',
                $r['created_at'], $r['finished_at'] ?? '',
            ];
        }
        $out = fopen('php://temp', 'r+');
        foreach ($lines as $line) {
            fputcsv($out, $line, ',', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        // 带 BOM，Excel 才认得 UTF-8 中文
        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $id): array
    {
        $order = $this->findOrFail($id);
        $attempts = $this->attemptDao->forOrder($order->id);
        $suppliers = $this->supplierDao->newQuery()->whereIn('id', $attempts->pluck('supplier_id')->unique()->all())->pluck('name', 'id');
        $supplierProducts = $this->supplierProductDao->newQuery()->whereIn('id', $attempts->pluck('supplier_product_id')->unique()->all())->pluck('name', 'id');

        return $this->formatMany([$order])[0] + [
            'attempts' => $attempts->map(static fn (OrderAttempt $a) => [
                'id' => $a->id,
                'attempt_no' => $a->attempt_no,
                'supplier_id' => $a->supplier_id,
                'supplier_name' => $suppliers[$a->supplier_id] ?? null,
                'supplier_product_name' => $supplierProducts[$a->supplier_product_id] ?? null,
                'cost_price' => $a->cost_price,
                'supplier_order_no' => $a->supplier_order_no,
                'status' => $a->status,
                'message' => $a->message,
                'request' => $a->request,
                'response' => $a->response,
                'submitted_at' => $a->submitted_at?->toDateTimeString(),
                'last_queried_at' => $a->last_queried_at?->toDateTimeString(),
                'finished_at' => $a->finished_at?->toDateTimeString(),
            ])->values()->all(),
            'notify_logs' => $this->notifyLogDao->newQuery()->where('order_id', $order->id)->orderBy('id')->get()
                ->map(static fn (OrderNotifyLog $l) => [
                    'attempt_no' => $l->attempt_no,
                    'url' => $l->url,
                    'http_status' => $l->http_status,
                    'response' => $l->response,
                    'success' => $l->success,
                    'created_at' => $l->created_at?->toDateTimeString(),
                ])->values()->all(),
            'balance_logs' => $this->balanceLogDao->newQuery()->where('order_id', $order->id)->orderBy('id')->get()
                ->map(static fn (MerchantBalanceLog $l) => [
                    'type' => $l->type,
                    'amount' => $l->amount,
                    'balance_after' => $l->balance_after,
                    'created_at' => $l->created_at?->toDateTimeString(),
                ])->values()->all(),
        ];
    }

    /**
     * 对还在处理中的提交立即查一次单。
     *
     * @return array<string, mixed>
     */
    public function query(AdminUser $operator, int $id, ?string $ip): array
    {
        $order = $this->findOrFail($id);
        $processing = $this->attemptDao->forOrder($order->id)->where('status', OrderAttempt::STATUS_PROCESSING);
        if ($processing->isEmpty()) {
            throw new HttpException(422, '这个订单没有在处理中的供应商提交，不用查单');
        }
        $results = [];
        foreach ($processing as $attempt) {
            $results[$attempt->attempt_no] = $this->processService->queryAttempt($attempt)->status->value;
        }
        $this->operationLogDao->record($operator->id, self::MODULE, 'query_order', 'order', $order->id, null, ['results' => $results], $ip);

        return $this->detail($order->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmSuccess(AdminUser $operator, int $id, mixed $remark, ?string $ip): array
    {
        $order = $this->findOrFail($id);
        $remark = $this->requiredString($remark, '备注', 200);
        if ($order->status !== Order::STATUS_ABNORMAL) {
            throw new HttpException(422, '只有异常订单可以人工置成功');
        }
        if (! $this->processService->confirmSuccess($order, $remark)) {
            throw new HttpException(422, '订单状态已经变化，请刷新后再操作');
        }
        $this->operationLogDao->record($operator->id, self::MODULE, 'confirm_order_success', 'order', $order->id, ['status' => $order->status], ['status' => Order::STATUS_SUCCESS, 'remark' => $remark], $ip);

        return $this->detail($order->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmFailed(AdminUser $operator, int $id, mixed $remark, ?string $ip): array
    {
        $order = $this->findOrFail($id);
        $remark = $this->requiredString($remark, '备注', 200);
        if ($order->status !== Order::STATUS_ABNORMAL) {
            throw new HttpException(422, '只有异常订单可以人工置失败');
        }
        if (! $this->processService->confirmFailed($order, $remark)) {
            throw new HttpException(422, '订单状态已经变化，请刷新后再操作');
        }
        $this->operationLogDao->record($operator->id, self::MODULE, 'confirm_order_failed', 'order', $order->id, ['status' => $order->status], ['status' => Order::STATUS_FAILED, 'refund' => $order->sale_price, 'remark' => $remark], $ip);

        return $this->detail($order->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function reverse(AdminUser $operator, int $id, mixed $remark, ?string $ip): array
    {
        $order = $this->findOrFail($id);
        $remark = $this->requiredString($remark, '冲正原因', 200);
        if ($order->status !== Order::STATUS_SUCCESS) {
            throw new HttpException(422, '只有成功的订单可以冲正');
        }
        if (! $this->processService->reverse($order, $remark)) {
            throw new HttpException(422, '订单状态已经变化，请刷新后再操作');
        }
        $this->operationLogDao->record($operator->id, self::MODULE, 'reverse_order', 'order', $order->id, ['status' => $order->status], ['status' => Order::STATUS_FAILED, 'refund' => $order->sale_price, 'remark' => $remark], $ip);

        return $this->detail($order->id);
    }

    /**
     * 从第 1 次开始重新通知商户。
     *
     * @return array<string, mixed>
     */
    public function resendNotify(AdminUser $operator, int $id, ?string $ip): array
    {
        $order = $this->findOrFail($id);
        if (! $order->isFinal()) {
            throw new HttpException(422, '订单还没出结果，不能通知');
        }
        $this->orderDao->update($order->id, ['notify_status' => Order::NOTIFY_PENDING]);
        $this->dispatcher->notify($order->id);
        $this->operationLogDao->record($operator->id, self::MODULE, 'resend_order_notify', 'order', $order->id, null, null, $ip);

        return $this->detail($order->id);
    }

    private function findOrFail(int $id): Order
    {
        $order = $this->orderDao->find($id);
        if (! $order) {
            throw new HttpException(404, '订单不存在');
        }

        return $order;
    }

    /**
     * 没传日期、也不是按单号 / 手机号 / 未完成状态查时，默认最近 7 天（见 FiltersByDateRange）。
     *
     * @param array<string, mixed> $query merchant_id / supplier_id / status / operator / province / keyword（平台或商户订单号）/ mobile / created_from / created_to
     */
    private function filtered(array $query): Builder
    {
        $builder = $this->orderDao->newQuery();
        foreach (['merchant_id', 'supplier_id', 'product_id'] as $field) {
            if (isset($query[$field]) && is_numeric($query[$field])) {
                $builder->where($field, (int) $query[$field]);
            }
        }
        if (in_array($query['status'] ?? null, self::STATUSES, true)) {
            $builder->where('status', $query['status']);
        }
        if (is_string($query['operator'] ?? null) && Operator::tryFrom($query['operator']) !== null) {
            $builder->where('operator', $query['operator']);
        }
        if (is_string($query['province'] ?? null) && Province::isValid($query['province'])) {
            $builder->where('province', $query['province']);
        }
        $keyword = trim((string) ($query['keyword'] ?? ''));
        if ($keyword !== '') {
            $builder->where(fn ($q) => $q->where('order_no', $keyword)->orWhere('merchant_order_no', $keyword));
        }
        $mobile = trim((string) ($query['mobile'] ?? ''));
        if ($mobile !== '') {
            $builder->where('mobile', $mobile);
        }
        // 未完成的订单（尤其是异常订单）不管多久都要能看到，数量也少，不加默认时间范围
        $unfinished = in_array($query['status'] ?? null, [Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL], true);
        $this->applyDateRange($builder, $query, $keyword !== '' || $mobile !== '' || $unfinished);

        return $builder;
    }

    /**
     * @param list<Order> $orders
     * @return list<array<string, mixed>>
     */
    private function formatMany(array $orders): array
    {
        $merchants = $this->merchantDao->newQuery()->whereIn('id', array_unique(array_map(static fn (Order $o) => $o->merchant_id, $orders)))->pluck('name', 'id');
        $suppliers = $this->supplierDao->newQuery()->whereIn('id', array_filter(array_unique(array_map(static fn (Order $o) => $o->supplier_id, $orders))))->pluck('name', 'id');

        return array_map(static fn (Order $o) => [
            'id' => $o->id,
            'order_no' => $o->order_no,
            'merchant_id' => $o->merchant_id,
            'merchant_name' => $merchants[$o->merchant_id] ?? null,
            'merchant_order_no' => $o->merchant_order_no,
            'product_id' => $o->product_id,
            'product_code' => $o->product_code,
            'product_name' => $o->product_name,
            'mobile' => $o->mobile,
            'operator' => $o->operator,
            'province' => $o->province,
            'face_value' => $o->face_value,
            'sale_price' => $o->sale_price,
            'cost_price' => $o->cost_price,
            'supplier_id' => $o->supplier_id,
            'supplier_name' => $o->supplier_id ? ($suppliers[$o->supplier_id] ?? null) : null,
            'status' => $o->status,
            'fail_reason' => $o->fail_reason,
            'notify_url' => $o->notify_url,
            'notify_status' => $o->notify_status,
            'notified_at' => $o->notified_at?->toDateTimeString(),
            'created_at' => $o->created_at?->toDateTimeString(),
            'finished_at' => $o->finished_at?->toDateTimeString(),
        ], $orders);
    }
}
