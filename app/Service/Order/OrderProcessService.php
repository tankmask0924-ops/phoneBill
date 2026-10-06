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

namespace App\Service\Order;

use App\Dao\OrderAttemptDao;
use App\Dao\OrderDao;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Service\AbstractService;
use App\Service\Merchant\MerchantBalanceService;
use App\Service\Product\ProductRouteService;
use App\Service\Supplier\SupplierGateway;
use App\Supplier\RechargeResult;
use App\Supplier\RechargeStatus;
use App\Support\RedisLock;
use Hyperf\Contract\ConfigInterface;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * 订单处理（docs/project.md 3.2、3.3）：提交供应商 → 收结果 → 成功 / 换下一个供应商 / 全部失败退款。
 *
 * - 同一时间一个订单最多只有一次「处理中」的提交，结果没出来之前绝不换供应商（防重复充值）；
 * - 所有状态变更都是带条件的更新，回调、查单、人工操作同时到达也只生效一次；
 * - 退款和订单改为失败在同一个事务里；异步任务一律在事务提交后再推。
 */
class OrderProcessService extends AbstractService
{
    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderAttemptDao $attemptDao;

    #[Inject]
    protected ProductRouteService $productRouteService;

    #[Inject]
    protected SupplierGateway $gateway;

    #[Inject]
    protected MerchantBalanceService $balanceService;

    #[Inject]
    protected OrderDispatcher $dispatcher;

    #[Inject]
    protected RedisLock $lock;

    #[Inject]
    protected ConfigInterface $config;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 把订单提交给下一个还没试过的候选供应商商品；没有可试的就失败退款。
     */
    public function submit(int $orderId): void
    {
        $lockKey = "order:submit:{$orderId}";
        $token = $this->lock->acquire($lockKey, 60);
        if ($token === null) {
            return;
        }
        try {
            $order = $this->orderDao->find($orderId);
            if ($order === null || ! in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING], true)) {
                return;
            }
            // 供应商明确失败就在锁里直接换下一个，直到成功、处理中或没有候选
            while (true) {
                $attempts = $this->attemptDao->forOrder($order->id);
                if ($attempts->contains(static fn (OrderAttempt $a) => $a->status === OrderAttempt::STATUS_PROCESSING)) {
                    return;
                }

                $tried = $attempts->pluck('supplier_product_id')->all();
                $candidates = array_values(array_filter(
                    $this->productRouteService->candidates([$order->product_id], $order->operator, $order->province)[$order->product_id] ?? [],
                    static fn (array $c) => ! in_array($c['supplier_product_id'], $tried, true)
                ));
                if ($candidates === []) {
                    $last = $attempts->last()?->message;
                    $reason = $attempts->isEmpty() ? '没有可用的供应商通道' : '所有供应商都充值失败' . ($last ? "：{$last}" : '');
                    $this->fail($order, [Order::STATUS_PENDING, Order::STATUS_PROCESSING], $reason);

                    return;
                }

                $candidate = $candidates[0];
                $attempt = $this->attemptDao->create([
                    'order_id' => $order->id,
                    'attempt_no' => $order->order_no . sprintf('%02d', $attempts->count() + 1),
                    'supplier_id' => $candidate['supplier_id'],
                    'supplier_product_id' => $candidate['supplier_product_id'],
                    'cost_price' => $candidate['cost_price'],
                    'status' => OrderAttempt::STATUS_PROCESSING,
                    'submitted_at' => date('Y-m-d H:i:s'),
                ]);
                // 已经是处理中时 MySQL 不算影响行数，所以只对待提交的做状态变更，其余重新确认一次状态
                $stillOpen = $order->status === Order::STATUS_PENDING
                    ? $this->orderDao->transition($order->id, [Order::STATUS_PENDING], Order::STATUS_PROCESSING)
                    : $this->orderDao->find($order->id)?->status === Order::STATUS_PROCESSING;
                if (! $stillOpen) {
                    // 订单在这期间被转了异常 / 人工处理，这次提交作废
                    $this->attemptDao->transition($attempt->id, OrderAttempt::STATUS_PROCESSING, OrderAttempt::STATUS_FAILED, ['message' => '订单状态已变化，未提交', 'finished_at' => date('Y-m-d H:i:s')]);

                    return;
                }

                $order = $this->orderDao->find($order->id);
                $result = $this->gateway->recharge($order, $attempt);
                $this->attemptDao->newQuery()->where('id', $attempt->id)->update([
                    'request' => $result->request,
                    'response' => $result->response,
                ]);
                $this->applyResult($attempt->id, $result, false);
                if ($result->status !== RechargeStatus::Failed) {
                    return;
                }
                $order = $this->orderDao->find($order->id);
                if ($order->status !== Order::STATUS_PROCESSING) {
                    return;
                }
            }
        } finally {
            $this->lock->release($lockKey, $token);
        }
    }

    /**
     * 处理供应商给出的结果（提交返回、回调、查单都走这里）。
     *
     * @param bool $resubmitOnFailure 明确失败时是否推任务换下一个供应商；submit() 自己在锁里循环，传 false
     */
    public function applyResult(int $attemptId, RechargeResult $result, bool $resubmitOnFailure = true): void
    {
        $attempt = $this->attemptDao->find($attemptId);
        if ($attempt === null) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $extra = array_filter([
            'supplier_order_no' => $result->supplierOrderNo,
            'message' => $result->message === null ? null : mb_substr($result->message, 0, 255),
        ], static fn ($v) => $v !== null && $v !== '');

        switch ($result->status) {
            case RechargeStatus::Success:
                $orderUpdated = Db::transaction(function () use ($attempt, $extra, $now) {
                    if (! $this->attemptDao->transition($attempt->id, OrderAttempt::STATUS_PROCESSING, OrderAttempt::STATUS_SUCCESS, $extra + ['finished_at' => $now])) {
                        return false;
                    }

                    return $this->orderDao->transition($attempt->order_id, [Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL], Order::STATUS_SUCCESS, [
                        'supplier_id' => $attempt->supplier_id,
                        'supplier_product_id' => $attempt->supplier_product_id,
                        'cost_price' => $attempt->cost_price,
                        'finished_at' => $now,
                        'notify_status' => Order::NOTIFY_PENDING,
                    ]);
                });
                if ($orderUpdated) {
                    $this->dispatcher->notify($attempt->order_id);
                } elseif ($this->attemptDao->find($attempt->id)?->status === OrderAttempt::STATUS_SUCCESS) {
                    // 供应商说充上了，但订单已经被判失败（比如人工置失败、已退款）：可能资损，必须人工核对
                    $this->logger()->error('供应商成功但订单已不在处理中，请人工核对', ['attempt_no' => $attempt->attempt_no, 'order_id' => $attempt->order_id]);
                }
                break;
            case RechargeStatus::Failed:
                if (! $this->attemptDao->transition($attempt->id, OrderAttempt::STATUS_PROCESSING, OrderAttempt::STATUS_FAILED, $extra + ['finished_at' => $now])) {
                    return;
                }
                // 异常订单交给人工，不再自动换供应商
                if ($resubmitOnFailure && $this->orderDao->find($attempt->order_id)?->status === Order::STATUS_PROCESSING) {
                    $this->dispatcher->submit($attempt->order_id);
                }
                break;
            case RechargeStatus::Processing:
                if ($extra !== []) {
                    $this->attemptDao->newQuery()->where('id', $attempt->id)->where('status', OrderAttempt::STATUS_PROCESSING)->update($extra);
                }
                break;
        }
    }

    /**
     * 订单改为失败并退款，退款和改状态在同一个事务里；只有当前状态在 $from 里才生效。
     *
     * @param list<string> $from
     * @return bool 是否由这次调用改成了失败
     */
    public function fail(Order $order, array $from, string $reason): bool
    {
        $done = Db::transaction(function () use ($order, $from, $reason) {
            $changed = $this->orderDao->transition($order->id, $from, Order::STATUS_FAILED, [
                'fail_reason' => mb_substr($reason, 0, 255),
                'finished_at' => date('Y-m-d H:i:s'),
                'notify_status' => Order::NOTIFY_PENDING,
            ]);
            if ($changed) {
                $this->balanceService->change($order->merchant_id, MerchantBalanceLog::TYPE_ORDER_REFUND, $order->sale_price, $order->id, '订单失败退款');
            }

            return $changed;
        });
        if ($done) {
            $this->dispatcher->notify($order->id);
        }

        return $done;
    }

    /**
     * 定时任务：提交后一段时间还没结果的，主动向供应商查单。
     *
     * @return int 查了多少笔
     */
    public function queryDue(int $limit = 100): int
    {
        $afterMinutes = (int) $this->config->get('order.query_after_minutes', 5);
        $intervalMinutes = (int) $this->config->get('order.query_interval_minutes', 5);
        $attempts = $this->attemptDao->dueForQuery(
            date('Y-m-d H:i:s', time() - $afterMinutes * 60),
            date('Y-m-d H:i:s', time() - $intervalMinutes * 60),
            $limit
        );
        foreach ($attempts as $attempt) {
            $this->queryAttempt($attempt);
        }

        return $attempts->count();
    }

    /**
     * 向供应商查一次单并处理结果。
     */
    public function queryAttempt(OrderAttempt $attempt): RechargeResult
    {
        $order = $this->orderDao->find($attempt->order_id);
        $result = $this->gateway->query($order, $attempt);
        $this->attemptDao->newQuery()->where('id', $attempt->id)->update(['last_queried_at' => date('Y-m-d H:i:s')]);
        $this->applyResult($attempt->id, $result);

        return $result;
    }

    /**
     * 定时任务：下单后超过时限还没结果的转为异常订单，等人工处理（不自动退款）。
     *
     * @return int 转了多少笔
     */
    public function markAbnormal(int $limit = 500): int
    {
        $minutes = (int) $this->config->get('order.abnormal_after_minutes', 120);
        $ids = $this->orderDao->newQuery()
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->where('created_at', '<=', date('Y-m-d H:i:s', time() - $minutes * 60))
            ->limit($limit)
            ->pluck('id')
            ->all();
        $count = 0;
        foreach ($ids as $id) {
            if ($this->orderDao->transition($id, [Order::STATUS_PENDING, Order::STATUS_PROCESSING], Order::STATUS_ABNORMAL)) {
                ++$count;
                $this->logger()->warning('订单超时转异常', ['order_id' => $id]);
            }
        }

        return $count;
    }

    /**
     * 定时任务：超过 2 分钟没有任何「处理中」提交、也没出结果的订单（队列任务丢了），重新推一次。
     *
     * @return int 重推了多少笔
     */
    public function resubmitStuck(int $limit = 100): int
    {
        $ids = $this->orderDao->stuckIds(date('Y-m-d H:i:s', time() - 120), $limit);
        foreach ($ids as $id) {
            $this->dispatcher->submit($id);
        }

        return count($ids);
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('order');
    }
}
