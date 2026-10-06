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
 * 商户资金流水，只写不改。
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $type
 * @property string $amount
 * @property string $balance_after
 * @property null|int $order_id
 * @property null|string $remark
 * @property null|int $admin_user_id
 * @property Carbon $created_at
 */
class MerchantBalanceLog extends Model
{
    public const TYPE_RECHARGE = 'recharge';

    public const TYPE_DEDUCT = 'deduct';

    public const TYPE_ORDER_PAY = 'order_pay';

    public const TYPE_ORDER_REFUND = 'order_refund';

    public bool $timestamps = false;

    protected ?string $table = 'merchant_balance_logs';

    protected array $fillable = [
        'merchant_id',
        'type',
        'amount',
        'balance_after',
        'order_id',
        'remark',
        'admin_user_id',
        'created_at',
    ];

    protected array $dates = [
        'created_at',
    ];
}
