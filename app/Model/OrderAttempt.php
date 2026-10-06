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
 * 订单向某个供应商商品的一次提交。一个订单失败换供应商时会有多条。
 *
 * @property int $id
 * @property int $order_id
 * @property string $attempt_no 传给供应商的订单号
 * @property int $supplier_id
 * @property int $supplier_product_id
 * @property string $cost_price
 * @property null|string $supplier_order_no
 * @property string $status
 * @property null|string $message
 * @property null|string $request
 * @property null|string $response
 * @property Carbon $submitted_at
 * @property null|Carbon $last_queried_at
 * @property null|Carbon $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OrderAttempt extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected ?string $table = 'order_attempts';

    protected array $fillable = [
        'order_id',
        'attempt_no',
        'supplier_id',
        'supplier_product_id',
        'cost_price',
        'supplier_order_no',
        'status',
        'message',
        'request',
        'response',
        'submitted_at',
        'last_queried_at',
        'finished_at',
    ];

    protected array $dates = [
        'submitted_at',
        'last_queried_at',
        'finished_at',
    ];
}
