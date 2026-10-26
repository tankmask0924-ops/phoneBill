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
 * 支付宝转账加款的收款账户：开放平台应用（按支付宝订单号查账务明细认到账）+ 收款账号和固定收款码。每个商户指定用一个（merchants.alipay_account_id）。
 *
 * @property int $id
 * @property string $name 账户名称，后台区分用
 * @property string $app_id
 * @property string $private_key 应用私钥（Encrypter 加密）
 * @property string $alipay_public_key
 * @property string $account 收款支付宝账号
 * @property string $account_name 收款人姓名
 * @property null|string $qr_code 固定收款码图片（COS 对象 key）
 * @property string $min_amount
 * @property string $max_amount
 * @property string $status active / disabled
 * @property null|string $remark
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AlipayAccount extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected ?string $table = 'alipay_accounts';

    protected array $fillable = [
        'name',
        'app_id',
        'private_key',
        'alipay_public_key',
        'account',
        'account_name',
        'qr_code',
        'min_amount',
        'max_amount',
        'status',
        'remark',
    ];
}
