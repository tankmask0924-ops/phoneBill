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
 * @property null|int $supplier_id
 * @property null|int $supplier_product_id
 * @property string $status
 * @property null|string $fail_reason
 * @property null|string $notify_url
 * @property string $notify_status
 * @property null|Carbon $notified_at
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

    /** 超时没结果，等人工处理 */
    public const STATUS_ABNORMAL = 'abnormal';

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
        'supplier_id',
        'supplier_product_id',
        'status',
        'fail_reason',
        'notify_url',
        'notify_status',
        'notified_at',
        'finished_at',
    ];

    protected array $casts = [
        'face_value' => 'integer',
    ];

    protected array $dates = [
        'notified_at',
        'finished_at',
    ];

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCESS, self::STATUS_FAILED], true);
    }
}
