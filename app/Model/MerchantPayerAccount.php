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
 * 商户的付款账号：商户自己添加，平台审核通过后才能用来提交加款申请；修改后回到待审核。
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $type alipay / bank（PaymentAccountType）
 * @property string $account_name
 * @property string $account_no
 * @property null|string $bank_name 对公开户行
 * @property string $status pending / approved / rejected
 * @property null|string $reject_reason
 * @property null|int $reviewed_by
 * @property null|Carbon $reviewed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MerchantPayerAccount extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected ?string $table = 'merchant_payer_accounts';

    protected array $fillable = [
        'merchant_id',
        'type',
        'account_name',
        'account_no',
        'bank_name',
        'status',
        'reject_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected array $casts = [
        'reviewed_at' => 'datetime',
    ];
}
