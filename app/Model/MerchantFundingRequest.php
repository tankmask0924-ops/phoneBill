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
 * 商户加款申请：商户打款后提交凭证，平台审核通过按申请金额入账。付款账号、收款账户存的是提交时的快照。
 *
 * @property int $id
 * @property int $merchant_id
 * @property null|int $merchant_user_id
 * @property string $amount
 * @property null|int $payer_account_id
 * @property string $payer_type
 * @property string $payer_account_name
 * @property string $payer_account_no
 * @property null|string $payer_bank_name
 * @property null|int $receiving_account_id
 * @property string $receiving_type
 * @property string $receiving_account_name
 * @property string $receiving_account_no
 * @property null|string $receiving_bank_name
 * @property Carbon $paid_at 打款时间
 * @property null|string $remark 打款备注
 * @property list<string> $vouchers 凭证图片的对象存储 key
 * @property string $status pending / approved / rejected
 * @property null|string $review_remark
 * @property null|int $reviewed_by
 * @property null|Carbon $reviewed_at
 * @property null|int $balance_log_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MerchantFundingRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected ?string $table = 'merchant_funding_requests';

    protected array $fillable = [
        'merchant_id',
        'merchant_user_id',
        'amount',
        'payer_account_id',
        'payer_type',
        'payer_account_name',
        'payer_account_no',
        'payer_bank_name',
        'receiving_account_id',
        'receiving_type',
        'receiving_account_name',
        'receiving_account_no',
        'receiving_bank_name',
        'paid_at',
        'remark',
        'vouchers',
        'status',
        'review_remark',
        'reviewed_by',
        'reviewed_at',
        'balance_log_id',
    ];

    protected array $casts = [
        'vouchers' => 'array',
        'paid_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];
}
