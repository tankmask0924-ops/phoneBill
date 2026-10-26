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
 * 商户在线加款（支付宝转账）：商户扫平台的固定收款码转账后，填支付宝订单号和金额，系统按订单号查支付宝账务明细，到账就入账。
 *
 * @property int $id
 * @property string $order_no
 * @property int $merchant_id
 * @property null|int $merchant_user_id
 * @property null|int $alipay_account_id 收款的支付宝账户
 * @property string $amount 商户填的转账金额
 * @property string $pay_amount 入账金额（自动入账时等于填的金额，后台按流水确认时是流水的金额）
 * @property string $receive_account
 * @property string $receive_name
 * @property string $status pending 未到账 / paid 已到账 / expired 已过期（只有改版前的单）/ cancelled 已取消
 * @property null|Carbon $checked_at 最近一次查询支付宝的时间
 * @property null|string $check_message 最近一次查询的结果
 * @property null|Carbon $paid_at
 * @property null|string $confirm_type auto / manual
 * @property null|string $account_log_id
 * @property null|string $alipay_order_no 商户填的支付宝订单号（唯一，一笔转账只能提交一次）
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
        'alipay_account_id',
        'amount',
        'pay_amount',
        'receive_account',
        'receive_name',
        'status',
        'checked_at',
        'check_message',
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
        'checked_at' => 'datetime',
        'paid_at' => 'datetime',
        'trans_at' => 'datetime',
    ];
}
