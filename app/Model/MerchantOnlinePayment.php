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
 * 商户在线加款（支付宝扫码转账）：商户按应付金额转账、备注填支付单号，系统查支付宝账务明细认到账后自动入账。
 *
 * @property int $id
 * @property string $order_no
 * @property int $merchant_id
 * @property null|int $merchant_user_id
 * @property string $amount 商户输入的金额
 * @property string $pay_amount 应付金额（加了随机分），入账按这个
 * @property null|string $active_amount 占用中的应付金额，结束并过了核对期后清空
 * @property string $receive_account
 * @property string $receive_name
 * @property string $status pending / paid / expired / cancelled
 * @property Carbon $expires_at
 * @property Carbon $checking_until
 * @property null|Carbon $paid_at
 * @property null|string $confirm_type auto / manual
 * @property null|string $account_log_id
 * @property null|string $alipay_order_no
 * @property null|string $payer_account
 * @property null|string $trans_memo
 * @property null|Carbon $trans_at
 * @property null|string $remark
 * @property null|int $confirmed_by
 * @property null|int $balance_log_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MerchantOnlinePayment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const CONFIRM_AUTO = 'auto';

    public const CONFIRM_MANUAL = 'manual';

    protected ?string $table = 'merchant_online_payments';

    protected array $fillable = [
        'order_no',
        'merchant_id',
        'merchant_user_id',
        'amount',
        'pay_amount',
        'active_amount',
        'receive_account',
        'receive_name',
        'status',
        'expires_at',
        'checking_until',
        'paid_at',
        'confirm_type',
        'account_log_id',
        'alipay_order_no',
        'payer_account',
        'trans_memo',
        'trans_at',
        'remark',
        'confirmed_by',
        'balance_log_id',
    ];

    protected array $casts = [
        'expires_at' => 'datetime',
        'checking_until' => 'datetime',
        'paid_at' => 'datetime',
        'trans_at' => 'datetime',
    ];
}
