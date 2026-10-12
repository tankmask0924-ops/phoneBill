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
 * 分配给商户的收款账户。
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $receiving_account_id
 * @property null|Carbon $created_at
 */
class MerchantReceivingAccount extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'merchant_receiving_accounts';

    protected array $fillable = [
        'merchant_id',
        'receiving_account_id',
        'created_at',
    ];
}
