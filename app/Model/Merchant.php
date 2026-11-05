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

use App\Service\Merchant\MerchantLookupService;
use Carbon\Carbon;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\Model\Events\Saved;

/**
 * 商户。app_secret 是加密后的签名密钥；balance 只能通过 MerchantBalanceService 改。
 *
 * @property int $id
 * @property string $name
 * @property null|string $contact
 * @property null|string $phone
 * @property string $app_key
 * @property string $app_secret
 * @property string $balance
 * @property string $credit_limit 授信额度，余额最低可以扣到它的负数
 * @property null|string $balance_alert_threshold 余额低于它发短信提醒充值，空表示不提醒
 * @property bool $balance_alert_armed 跌破阈值时是否要提醒：提醒后为 false，余额回到阈值以上再为 true
 * @property null|Carbon $balance_alert_date 最近一次发余额提醒的日期
 * @property int $balance_alert_count balance_alert_date 当天发了几次提醒
 * @property string $consumed_total consumed_until 之前下单的成功订单售价合计（MerchantConsumptionService 结转）
 * @property null|Carbon $consumed_until 累计消费结转到这天（不含）
 * @property int $rate_limit_seconds 下单限流：每这么多秒（0 不限）
 * @property int $rate_limit_orders 下单限流：最多这么多单（0 不限）
 * @property null|string $notify_url
 * @property null|string $ip_whitelist
 * @property string $status
 * @property null|string $remark
 * @property null|int $alipay_account_id 支付宝转账加款用的收款账户，空表示不能用
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Merchant extends Model
{
    protected ?string $table = 'merchants';

    protected array $fillable = [
        'name',
        'contact',
        'phone',
        'app_key',
        'app_secret',
        'balance',
        'credit_limit',
        'balance_alert_threshold',
        'rate_limit_seconds',
        'rate_limit_orders',
        'notify_url',
        'ip_whitelist',
        'status',
        'remark',
    ];

    protected array $casts = [
        'rate_limit_seconds' => 'integer',
        'rate_limit_orders' => 'integer',
        'balance_alert_armed' => 'boolean',
        'balance_alert_date' => 'date',
        'balance_alert_count' => 'integer',
        'consumed_until' => 'date',
    ];

    /**
     * 可用额度 = 余额 + 授信额度。
     */
    public function availableBalance(): string
    {
        return bcadd((string) $this->balance, (string) ($this->credit_limit ?? '0'), 2);
    }

    /**
     * @return list<string> IP 白名单（单个 IP 或 CIDR），空数组表示不限制
     */
    public function ipWhitelist(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->ip_whitelist)), static fn ($ip) => $ip !== ''));
    }

    /**
     * 资料、状态、密钥变了，清掉按 AppKey 的缓存（见 MerchantLookupService）。余额不在缓存里，用查询构造器改不会走到这里。
     */
    public function saved(Saved $event): void
    {
        ApplicationContext::getContainer()->get(MerchantLookupService::class)->forget($this->app_key);
    }
}
