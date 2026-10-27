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
use App\Service\Setting\OrderSettingService;
use App\Service\Supplier\SupplierGateway;
use App\Supplier\RechargeResult;
use App\Supplier\RechargeStatus;
use App\Support\RedisLock;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Coroutine\Parallel;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

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
    protected OrderSettingService $orderSettingService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 把订单提交给下一个还没试过的候选供应商商品；没有可试的就失败退款。
     *
     * @param null|int $preferredSupplierProductId 手动补单指定的供应商商品：试过的也可以再提交一次；已经不能用了就按正常顺序
     */
    public function submit(int $orderId, ?int $preferredSupplierProductId = null): void
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

                $all = $this->productRouteService->candidates([$order->product_id], $order->operator, $order->province)[$order->product_id] ?? [];
                $candidates = [];
                if ($preferredSupplierProductId !== null) {
                    $candidates = array_values(array_filter($all, static fn (array $c) => (int) $c['supplier_product_id'] === $preferredSupplierProductId));
                    $preferredSupplierProductId = null;
                }
                if ($candidates === []) {
                    $tried = $attempts->pluck('supplier_product_id')->all();
                    $candidates = array_values(array_filter($all, static fn (array $c) => ! in_array($c['supplier_product_id'], $tried, true)));
                }
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
                if ($result->callFailed) {
                    $this->markAbnormalAfterCallFailure($order, $attempt, $result);

                    return;
                }
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
        // 人工处理单、已手动回调的订单：上游结果只记到这次提交上，不改订单、不推给商户
        $order = $this->orderDao->find($attempt->order_id);
        $hold = $order !== null && $order->holdsUpstreamResults();

        switch ($result->status) {
            case RechargeStatus::Success:
                if ($hold) {
                    if ($this->attemptDao->transition($attempt->id, OrderAttempt::STATUS_PROCESSING, OrderAttempt::STATUS_SUCCESS, $extra + ['finished_at' => $now])) {
                        $this->logHeldResult($order, $attempt, RechargeStatus::Success);
                    }
                    break;
                }
                $orderUpdated = Db::transaction(function () use ($order, $attempt, $extra, $now) {
                    if (! $this->attemptDao->transition($attempt->id, OrderAttempt::STATUS_PROCESSING, OrderAttempt::STATUS_SUCCESS, $extra + ['finished_at' => $now])) {
                        return false;
                    }

                    return $this->orderDao->transition($attempt->order_id, [Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL, Order::STATUS_TIMEOUT], Order::STATUS_SUCCESS, [
                        'supplier_id' => $attempt->supplier_id,
                        'supplier_product_id' => $attempt->supplier_product_id,
                        'cost_price' => $attempt->cost_price,
                        'profit' => self::profit($order, $attempt),
                        'finished_at' => $now,
                        'notify_status' => Order::NOTIFY_PENDING,
                    ]);
                });
                if ($orderUpdated) {
                    $this->dispatcher->notify($attempt->order_id, 1, 0, $order?->notify_round ?? 0);
                } elseif ($this->attemptDao->find($attempt->id)?->status === OrderAttempt::STATUS_SUCCESS) {
                    // 供应商说充上了，但订单已经被判失败（比如人工置失败、已退款）：可能资损，必须人工核对
                    $this->logger()->error('供应商成功但订单已不在处理中，请人工核对', ['attempt_no' => $attempt->attempt_no, 'order_id' => $attempt->order_id]);
                }
                break;
            case RechargeStatus::Failed:
                if (! $this->attemptDao->transition($attempt->id, OrderAttempt::STATUS_PROCESSING, OrderAttempt::STATUS_FAILED, $extra + ['finished_at' => $now])) {
                    if ($this->attemptDao->find($attempt->id)?->status === OrderAttempt::STATUS_SUCCESS) {
                        // 供应商事后撤销了已成功的充值（比如核实未到账退款）：不自动改订单，人工核对后在后台冲正
                        $this->logger()->error('供应商撤销了已成功的充值，请人工核对是否冲正', ['attempt_no' => $attempt->attempt_no, 'order_id' => $attempt->order_id, 'message' => $result->message]);
                    }

                    return;
                }
                if ($hold) {
                    $this->logHeldResult($order, $attempt, RechargeStatus::Failed);
                    break;
                }
                $status = $this->orderDao->find($attempt->order_id)?->status;
                if ($status === Order::STATUS_TIMEOUT) {
                    // 超时的单上游明确失败：不再换供应商，直接退款并通知商户（还有别的提交没出结果的等它）
                    if ($order !== null && ! $this->attemptDao->hasProcessingForOrder($order->id)) {
                        $this->fail($order, [Order::STATUS_TIMEOUT], '超时后上游返回失败' . ($result->message ? "：{$result->message}" : ''));
                    }
                } elseif ($resubmitOnFailure && $status === Order::STATUS_PROCESSING) {
                    // 异常订单交给人工，不再自动换供应商
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
        // 人工处理单不自动通知商户，人工手动回调
        $current = $this->orderDao->find($order->id);
        $notify = $current !== null && ! $current->manual_only;
        $done = Db::transaction(function () use ($order, $from, $reason, $notify) {
            $changed = $this->orderDao->transition($order->id, $from, Order::STATUS_FAILED, [
                // 成本保留便于对账，利润清掉（冲正的单不算利润）
                'profit' => null,
                'fail_reason' => mb_substr($reason, 0, 255),
                'finished_at' => date('Y-m-d H:i:s'),
                'notify_status' => $notify ? Order::NOTIFY_PENDING : Order::NOTIFY_NONE,
            ]);
            if ($changed) {
                $this->balanceService->change($order->merchant_id, MerchantBalanceLog::TYPE_ORDER_REFUND, $order->sale_price, $order->id, '订单失败退款');
            }

            return $changed;
        });
        if ($done && $notify) {
            $this->dispatcher->notify($order->id, 1, 0, $current->notify_round);
        }

        return $done;
    }

    /**
     * 人工确认成功（只用于异常、超时订单）：以最后一次提交的供应商和成本记账。
     *
     * @return bool 是否由这次调用改成了成功
     */
    public function confirmSuccess(Order $order, string $note): bool
    {
        $attempt = $this->attemptDao->forOrder($order->id)->last();
        if ($attempt === null) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        // 人工处理单不自动通知商户，人工手动回调
        $current = $this->orderDao->find($order->id);
        $notify = $current !== null && ! $current->manual_only;
        $done = Db::transaction(function () use ($order, $attempt, $note, $now, $notify) {
            $changed = $this->orderDao->transition($order->id, [Order::STATUS_ABNORMAL, Order::STATUS_TIMEOUT], Order::STATUS_SUCCESS, [
                'supplier_id' => $attempt->supplier_id,
                'supplier_product_id' => $attempt->supplier_product_id,
                'cost_price' => $attempt->cost_price,
                'profit' => self::profit($order, $attempt),
                'finished_at' => $now,
                'notify_status' => $notify ? Order::NOTIFY_PENDING : Order::NOTIFY_NONE,
            ]);
            if ($changed) {
                $this->attemptDao->transition($attempt->id, OrderAttempt::STATUS_PROCESSING, OrderAttempt::STATUS_SUCCESS, [
                    'message' => mb_substr("人工确认成功：{$note}", 0, 255),
                    'finished_at' => $now,
                ]);
            }

            return $changed;
        });
        if ($done && $notify) {
            $this->dispatcher->notify($order->id, 1, 0, $current->notify_round);
        }

        return $done;
    }

    /**
     * 人工确认失败（只用于异常、超时订单）：还在处理中的提交一并置为失败，订单失败并退款。
     */
    public function confirmFailed(Order $order, string $note): bool
    {
        foreach ($this->attemptDao->forOrder($order->id) as $attempt) {
            $this->attemptDao->transition($attempt->id, OrderAttempt::STATUS_PROCESSING, OrderAttempt::STATUS_FAILED, [
                'message' => mb_substr("人工确认失败：{$note}", 0, 255),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->fail($order, [Order::STATUS_ABNORMAL, Order::STATUS_TIMEOUT], "人工确认失败：{$note}");
    }

    /**
     * 冲正：成功的订单被供应商事后撤销，改为失败并退款。
     */
    public function reverse(Order $order, string $note): bool
    {
        return $this->fail($order, [Order::STATUS_SUCCESS], "冲正：{$note}");
    }

    /**
     * 手动回调前按人工选的结果改订单，任何状态都可以；不通知商户，由调用方开新一轮手动回调。
     * 改状态时一并记「已手动回调」，之后上游再来的结果只记录、不再改订单（见 Order::holdsUpstreamResults()）。
     *
     * - 置成功：以最后一次提交的供应商和成本记账，还在处理中的那次提交记为成功；失败（已退款）的订单重新扣款；
     * - 置失败：退款，还在处理中的提交一并置为失败；成功的订单相当于冲正；
     * - 已经是这个结果的不改。
     *
     * @param string $to Order::STATUS_SUCCESS / Order::STATUS_FAILED
     * @return bool 订单现在是不是 $to；false 表示订单状态在这期间被别处改了
     */
    public function settleManually(Order $order, string $to, string $note): bool
    {
        if ($order->status === $to) {
            return true;
        }
        $now = date('Y-m-d H:i:s');
        $marked = ['manual_notified_at' => $now, 'finished_at' => $now, 'notify_status' => Order::NOTIFY_PENDING];
        $note = $to === Order::STATUS_SUCCESS ? "手动回调置为成功：{$note}" : "手动回调置为失败：{$note}";

        return Db::transaction(function () use ($order, $to, $note, $now, $marked) {
            $attempts = $this->attemptDao->forOrder($order->id);
            if ($to === Order::STATUS_FAILED) {
                if (! $this->orderDao->transition($order->id, [$order->status], Order::STATUS_FAILED, ['profit' => null, 'fail_reason' => mb_substr($note, 0, 255)] + $marked)) {
                    return false;
                }
                foreach ($attempts as $attempt) {
                    $this->attemptDao->transition($attempt->id, OrderAttempt::STATUS_PROCESSING, OrderAttempt::STATUS_FAILED, ['message' => mb_substr($note, 0, 255), 'finished_at' => $now]);
                }
                $this->balanceService->change($order->merchant_id, MerchantBalanceLog::TYPE_ORDER_REFUND, $order->sale_price, $order->id, '手动回调置为失败退款');

                return true;
            }

            $attempt = $attempts->last();
            $booked = $attempt === null ? [] : [
                'supplier_id' => $attempt->supplier_id,
                'supplier_product_id' => $attempt->supplier_product_id,
                'cost_price' => $attempt->cost_price,
                'profit' => self::profit($order, $attempt),
            ];
            if (! $this->orderDao->transition($order->id, [$order->status], Order::STATUS_SUCCESS, ['fail_reason' => null] + $booked + $marked)) {
                return false;
            }
            if ($attempt !== null) {
                $this->attemptDao->transition($attempt->id, OrderAttempt::STATUS_PROCESSING, OrderAttempt::STATUS_SUCCESS, ['message' => mb_substr($note, 0, 255), 'finished_at' => $now]);
            }
            if ($order->status === Order::STATUS_FAILED) {
                // 失败时已经退过款，改成功要重新扣。钱已经花在供应商那边，可用额度不够也照扣（余额可能超出授信变成更多的负数）
                $this->balanceService->change($order->merchant_id, MerchantBalanceLog::TYPE_ORDER_PAY, bcmul($order->sale_price, '-1', 2), $order->id, '手动回调失败改为成功，重新扣款', null, true);
            }

            return true;
        });
    }

    /**
     * 定时任务：提交后一段时间还没结果的，主动向供应商查单。并发查，单笔出错不影响其他的。
     *
     * @return int 查了多少笔
     */
    public function queryDue(int $limit = 500, int $concurrency = 20): int
    {
        $afterMinutes = (int) $this->config->get('order.query_after_minutes', 5);
        $intervalMinutes = (int) $this->config->get('order.query_interval_minutes', 5);
        $attempts = $this->attemptDao->dueForQuery(
            date('Y-m-d H:i:s', time() - $afterMinutes * 60),
            date('Y-m-d H:i:s', time() - $intervalMinutes * 60),
            $limit
        );
        $parallel = new Parallel($concurrency);
        foreach ($attempts as $attempt) {
            $parallel->add(function () use ($attempt) {
                try {
                    $this->queryAttempt($attempt);
                } catch (Throwable $e) {
                    $this->logger()->error('查单出错', ['attempt_no' => $attempt->attempt_no, 'error' => $e->getMessage()]);
                }
            });
        }
        $parallel->wait(false);

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
     * 定时任务：下单后超过超时时间（后台下单设置，见 OrderSettingService::timeoutMinutes()）还没结果的订单。
     * - 已提交（处理中）的转为超时：照常查单、收回调，上游成功就改成功，失败就直接退款，都通知商户；
     * - 一直没提交出去（等待中）的不会有回调，转为异常订单，等人工处理（不自动退款）。
     *
     * @return int 转了多少笔
     */
    public function markTimeout(int $limit = 500): int
    {
        $minutes = $this->orderSettingService->timeoutMinutes();
        $rows = $this->orderDao->newQuery()
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->where('created_at', '<=', date('Y-m-d H:i:s', time() - $minutes * 60))
            ->limit($limit)
            ->get(['id', 'status']);
        $count = 0;
        foreach ($rows as $row) {
            $to = $row->status === Order::STATUS_PROCESSING ? Order::STATUS_TIMEOUT : Order::STATUS_ABNORMAL;
            if ($this->orderDao->transition($row->id, [$row->status], $to)) {
                ++$count;
                $this->logger()->warning($to === Order::STATUS_TIMEOUT ? '订单超时' : '订单超时没提交出去，转异常', ['order_id' => $row->id]);
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

    /**
     * 利润 = 扣款 - 这次提交的成本。
     */
    private static function profit(?Order $order, OrderAttempt $attempt): ?string
    {
        return $order === null || $attempt->cost_price === null ? null : bcsub($order->sale_price, $attempt->cost_price, 2);
    }

    /**
     * 只记录、不采用的上游结果：和订单已有的结果矛盾时记 error，提醒人工核对资金。
     */
    private function logHeldResult(Order $order, OrderAttempt $attempt, RechargeStatus $upstream): void
    {
        $context = ['order_id' => $order->id, 'attempt_no' => $attempt->attempt_no, 'order_status' => $order->status, 'upstream' => $upstream->value];
        $conflict = ($upstream === RechargeStatus::Success && $order->status === Order::STATUS_FAILED)
            || ($upstream === RechargeStatus::Failed && $order->status === Order::STATUS_SUCCESS);
        if ($conflict) {
            $this->logger()->error('上游结果和订单结果不一致（人工处理单 / 已手动回调），请人工核对资金', $context);
        } else {
            $this->logger()->info('人工处理单 / 已手动回调：上游结果只记录，不改订单、不通知商户', $context);
        }
    }

    /**
     * 提交时调供应商接口出了异常（连不上、超时、握手失败等）：上游可能已经建单（实测连接超时的单商腾那边照样建了），
     * 判失败换下一家可能重复充值，等 2 小时又太久，所以立即转异常订单，并标为人工处理单：
     * 之后上游的回调、查单结果只记录，不改订单、不通知商户；人工置为成功 / 失败也不自动通知，人工手动回调。
     */
    private function markAbnormalAfterCallFailure(Order $order, OrderAttempt $attempt, RechargeResult $result): void
    {
        if ($this->orderDao->transition($order->id, [Order::STATUS_PROCESSING], Order::STATUS_ABNORMAL, ['manual_only' => true])) {
            $this->logger()->warning('提交供应商时接口异常，订单转异常', [
                'order_id' => $order->id,
                'attempt_no' => $attempt->attempt_no,
                'message' => $result->message,
            ]);
        }
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('order');
    }
}
