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

namespace App\Model;

use Carbon\Carbon;

/**
 * 充值订单，状态流转见 docs/project.md 3.3。状态变更一律带条件更新（见 OrderDao::transition()）。
 *
 * @property int $id
 * @property string $order_no
 * @property int $merchant_id
 * @property string $merchant_order_no
 * @property int $product_id
 * @property string $product_code
 * @property string $product_name
 * @property string $mobile
 * @property string $operator
 * @property string $province
 * @property int $face_value
 * @property string $sale_price
 * @property null|string $cost_price
 * @property null|string $profit 利润 = 扣款 - 成本，成功时写入，失败（含冲正）为空
 * @property null|int $supplier_id
 * @property null|int $supplier_product_id
 * @property string $status
 * @property null|string $fail_reason
 * @property null|string $notify_url
 * @property string $notify_status
 * @property null|Carbon $notified_at
 * @property bool $manual_only 人工处理单：提交时接口异常，上游结果只记录，状态和回调全部人工
 * @property null|Carbon $manual_notified_at 最近一次手动回调时间
 * @property int $notify_round 通知轮次，每次手动回调加 1
 * @property null|Carbon $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Order extends Model
{
    /** 已扣款，还没提交给供应商 */
    public const STATUS_PENDING = 'pending';

    /** 已提交，等结果 */
    public const STATUS_PROCESSING = 'processing';

    /** 等人工处理：提交时接口异常（人工处理单），或者一直没提交出去的单超时了 */
    public const STATUS_ABNORMAL = 'abnormal';

    /** 提交后超时没结果：照常查单、收回调，上游成功就改成功，失败就直接退款（不再换供应商） */
    public const STATUS_TIMEOUT = 'timeout';

    public const STATUS_SUCCESS = 'success';

    /** 失败，余额已退回 */
    public const STATUS_FAILED = 'failed';

    public const NOTIFY_NONE = 'none';

    public const NOTIFY_PENDING = 'pending';

    public const NOTIFY_RETRYING = 'retrying';

    public const NOTIFY_SUCCESS = 'success';

    public const NOTIFY_FAILED = 'failed';

    protected ?string $table = 'orders';

    protected array $fillable = [
        'order_no',
        'merchant_id',
        'merchant_order_no',
        'product_id',
        'product_code',
        'product_name',
        'mobile',
        'operator',
        'province',
        'face_value',
        'sale_price',
        'cost_price',
        'profit',
        'supplier_id',
        'supplier_product_id',
        'status',
        'fail_reason',
        'notify_url',
        'notify_status',
        'notified_at',
        'finished_at',
        'manual_only',
        'manual_notified_at',
        'notify_round',
    ];

    protected array $casts = [
        'face_value' => 'integer',
        'manual_only' => 'boolean',
        'notify_round' => 'integer',
    ];

    protected array $dates = [
        'notified_at',
        'finished_at',
        'manual_notified_at',
    ];

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCESS, self::STATUS_FAILED], true);
    }

    /**
     * 商户能不能看到最终结果：人工处理单要手动回调之后才给商户看，之前查单一律是处理中。
     */
    public function resultVisibleToMerchant(): bool
    {
        return $this->isFinal() && (! $this->manual_only || $this->manual_notified_at !== null);
    }

    /**
     * 列表上标「异常」：已经转异常的；或者还在充值中、但上游返回不明确的（见 OrderAttemptDao::unclearProcessing()）。
     */
    public function flaggedAbnormal(bool $unclearReply): bool
    {
        return $this->status === self::STATUS_ABNORMAL
            || ($unclearReply && in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_TIMEOUT], true));
    }

    /**
     * 商户看到的是「充值中」、但平台这边是异常的：比 flaggedAbnormal() 多了人工处理单置了结果、还没手动回调的。
     */
    public function abnormalForMerchant(bool $unclearReply): bool
    {
        return ! $this->resultVisibleToMerchant() && ($this->manual_only || $this->flaggedAbnormal($unclearReply));
    }

    /**
     * 上游的结果只记录、不改订单、不推给商户：人工处理单，或者已经手动回调过的订单。
     */
    public function holdsUpstreamResults(): bool
    {
        return $this->manual_only || $this->manual_notified_at !== null;
    }
}
