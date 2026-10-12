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
use App\Service\Product\ProductRouteService;
use App\Support\RedisLock;
use Carbon\CarbonInterface;
use Hyperf\Database\Model\Builder;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 订单中心：列表、详情、导出，以及人工处理（docs/project.md 4.7）。
 *
 * - 置成功 / 置失败只对异常订单；冲正只对成功订单，要单独权限；三者都必须写备注，都记操作日志；
 * - 置失败、冲正都会退款并通知商户；人工处理单（提交时接口异常）不自动通知，人工手动回调；
 * - 手动回调只对成功 / 失败的订单：开新一轮通知，之前排队的通知作废；回调过的订单之后上游结果只记录、不再推给商户；
 * - 手动补单只对异常订单：同一笔订单重新提交给指定的供应商商品，不新建订单、不重复扣款；
 *   原来还在处理中的提交记为「人工确认上游未成功」，补单前必须先到上游确认原单没有充上，否则会重复充值。
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
    protected ProductRouteService $routeService;

    #[Inject]
    protected RedisLock $lock;

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
        $lines = [['平台订单号', '商户订单号', '商户', '商品', '手机号', '运营商', '省份', '面值', '扣款金额', '成本', '利润', '供应商', '状态', '失败原因', '下单时间', '完成时间', '耗时（秒）']];
        foreach ($rows as $r) {
            $lines[] = [
                $r['order_no'] . "\t", $r['merchant_order_no'] . "\t", $r['merchant_name'], $r['product_name'], $r['mobile'] . "\t",
                Operator::tryFrom($r['operator'])?->label() ?? $r['operator'], $r['province'], $r['face_value'], $r['sale_price'],
                $r['cost_price'] ?? '', $r['profit'] ?? '', $r['supplier_name'] ?? '', $statusLabels[$r['status']] ?? $r['status'], $r['fail_reason'] ?? '',
                $r['created_at'], $r['finished_at'] ?? '', $r['duration'] ?? '',
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
            'warnings' => $this->warnings($order, $attempts->all()),
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
                // 提交给供应商到出结果（回调或查单）的秒数
                'duration' => self::seconds($a->submitted_at, $a->finished_at),
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
     * 人工查单：对还在处理中的提交立即查一次单并按结果处理（成功会把订单改为成功；失败时异常订单保持异常，等人工置为失败）。
     * 返回详情和这次每个提交查到的结果，前端据此提示下一步。
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
            $result = $this->processService->queryAttempt($attempt);
            $results[] = ['attempt_no' => $attempt->attempt_no, 'status' => $result->status->value, 'message' => $result->message];
        }
        $this->operationLogDao->record($operator->id, self::MODULE, 'query_order', 'order', $order->id, null, ['results' => $results], $ip);

        return $this->detail($order->id) + ['query_results' => $results];
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
     * 手动回调：把订单当前结果推给商户（从第 1 次开始，失败按间隔重试）。开新一轮通知，之前排队的通知作废；
     * 回调过的订单之后上游再来的结果只记录、不再推给商户。人工处理单要先人工置为成功 / 失败再回调。
     *
     * @return array<string, mixed>
     */
    public function manualNotify(AdminUser $operator, int $id, ?string $ip): array
    {
        $order = $this->findOrFail($id);
        if (! $order->isFinal()) {
            throw new HttpException(422, '订单还没有成功或失败，不能回调；异常订单请先置为成功或失败');
        }
        $this->orderDao->newQuery()->where('id', $order->id)->increment('notify_round', 1, [
            'manual_notified_at' => date('Y-m-d H:i:s'),
            'notify_status' => Order::NOTIFY_PENDING,
        ]);
        $round = (int) $this->findOrFail($order->id)->notify_round;
        $this->dispatcher->notify($order->id, 1, 0, $round);
        $this->operationLogDao->record($operator->id, self::MODULE, 'manual_notify_order', 'order', $order->id, null, ['status' => $order->status, 'round' => $round], $ip);

        return $this->detail($order->id);
    }

    /**
     * 手动补单可选的供应商商品：这笔订单现在能走的全部候选，标出试过的。
     *
     * @return list<array<string, mixed>>
     */
    public function resupplyOptions(int $id): array
    {
        $order = $this->findOrFail($id);
        $tried = $this->attemptDao->forOrder($order->id)->pluck('supplier_product_id')->map(static fn ($v) => (int) $v)->all();
        $candidates = $this->routeService->candidates([$order->product_id], $order->operator, $order->province)[$order->product_id] ?? [];

        return array_map(static fn (array $c) => [
            'supplier_product_id' => $c['supplier_product_id'],
            'supplier_product_name' => $c['supplier_product_name'],
            'supplier_name' => $c['supplier_name'],
            'cost_price' => $c['cost_price'],
            'priority' => $c['priority'],
            'tried' => in_array($c['supplier_product_id'], $tried, true),
        ], $candidates);
    }

    /**
     * 手动补单：异常订单重新提交给指定的供应商商品（同一笔订单，不重复扣款），提交在队列里异步进行。
     *
     * @return array<string, mixed>
     */
    public function resupply(AdminUser $operator, int $id, mixed $supplierProductId, mixed $remark, ?string $ip): array
    {
        $order = $this->findOrFail($id);
        if ($order->status !== Order::STATUS_ABNORMAL) {
            throw new HttpException(422, '只有异常订单可以手动补单');
        }
        $remark = $this->requiredString($remark, '备注', 200);
        $spId = is_numeric($supplierProductId) ? (int) $supplierProductId : 0;
        if (! in_array($spId, array_column($this->resupplyOptions($order->id), 'supplier_product_id'), true)) {
            throw new HttpException(422, '请选择这笔订单现在能走的供应商商品');
        }
        // 和提交任务用同一把锁，防止补单时正好有提交在进行
        $token = $this->lock->acquire("order:submit:{$order->id}", 60);
        if ($token === null) {
            throw new HttpException(422, '订单正在提交中，请稍后再试');
        }
        try {
            $previous = [];
            foreach ($this->attemptDao->forOrder($order->id) as $attempt) {
                if ($this->attemptDao->transition($attempt->id, OrderAttempt::STATUS_PROCESSING, OrderAttempt::STATUS_FAILED, [
                    'message' => mb_substr("人工确认上游未成功（补单）：{$remark}", 0, 255),
                    'finished_at' => date('Y-m-d H:i:s'),
                ])) {
                    $previous[] = $attempt->attempt_no;
                }
            }
            // 补单后按正常流程自动处理；补单时再出接口异常会重新标为人工处理单
            if (! $this->orderDao->transition($order->id, [Order::STATUS_ABNORMAL], Order::STATUS_PROCESSING, ['manual_only' => false])) {
                throw new HttpException(422, '订单状态已经变了，请刷新');
            }
        } finally {
            $this->lock->release("order:submit:{$order->id}", $token);
        }
        $this->dispatcher->submit($order->id, 0, $spId);
        $this->operationLogDao->record($operator->id, self::MODULE, 'resupply_order', 'order', $order->id, ['status' => $order->status, 'closed_attempts' => $previous], [
            'status' => Order::STATUS_PROCESSING,
            'supplier_product_id' => $spId,
            'remark' => $remark,
        ], $ip);

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
            // 利润 = 扣款 - 成本，成功时写入；失败（含冲正）为空
            'profit' => $o->profit,
            'supplier_id' => $o->supplier_id,
            'supplier_name' => $o->supplier_id ? ($suppliers[$o->supplier_id] ?? null) : null,
            'status' => $o->status,
            'fail_reason' => $o->fail_reason,
            'notify_url' => $o->notify_url,
            'notify_status' => $o->notify_status,
            'notified_at' => $o->notified_at?->toDateTimeString(),
            // 人工处理单：提交时接口异常，上游结果只记录，状态和回调全部人工
            'manual_only' => $o->manual_only,
            // 最近一次手动回调；回调过之后上游结果不再推给商户
            'manual_notified_at' => $o->manual_notified_at?->toDateTimeString(),
            'created_at' => $o->created_at?->toDateTimeString(),
            'finished_at' => $o->finished_at?->toDateTimeString(),
            // 下单受理到出结果（成功或失败）的秒数，还没结果时为 null
            'duration' => self::seconds($o->created_at, $o->finished_at),
        ], $orders);
    }

    /**
     * 详情页标红的提醒：上游结果和订单结果矛盾（可能资损），以及人工处理单待办。
     *
     * @param list<OrderAttempt> $attempts
     * @return list<string>
     */
    private function warnings(Order $order, array $attempts): array
    {
        $warnings = [];
        $statuses = array_map(static fn (OrderAttempt $a) => $a->status, $attempts);
        if ($order->status === Order::STATUS_FAILED && in_array(OrderAttempt::STATUS_SUCCESS, $statuses, true)) {
            $warnings[] = '有供应商提交是成功的，但订单是失败（已退款），请核对资金';
        }
        if ($order->status === Order::STATUS_SUCCESS && $attempts !== [] && end($attempts)->status === OrderAttempt::STATUS_FAILED) {
            $warnings[] = '最后一次供应商提交是失败的，但订单是成功，请核对是否到账';
        }
        if ($order->manual_only && $order->manual_notified_at === null) {
            $warnings[] = $order->isFinal()
                ? '人工处理单：结果还没有回调给商户（商户查单看到的是处理中），核实无误后请点「手动回调」'
                : '人工处理单：提交时接口异常，上游结果只记录不自动处理。请到上游核实后置为成功 / 失败，或确认上游没有充上后手动补单';
        }

        return $warnings;
    }

    private static function seconds(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        return $from === null || $to === null ? null : max(0, $to->getTimestamp() - $from->getTimestamp());
    }
}
