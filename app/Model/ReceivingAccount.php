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
 * 收款账户：平台收商户打款的支付宝 / 对公账户，分配给商户后商户才看得到。
 *
 * @property int $id
 * @property string $type alipay / bank（PaymentAccountType）
 * @property string $account_name
 * @property string $account_no
 * @property null|string $bank_name 对公开户行
 * @property string $status active / disabled
 * @property null|string $remark
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ReceivingAccount extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected ?string $table = 'receiving_accounts';

    protected array $fillable = [
        'type',
        'account_name',
        'account_no',
        'bank_name',
        'status',
        'remark',
    ];
}
