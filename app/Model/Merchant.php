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
 * @property null|string $notify_url
 * @property null|string $ip_whitelist
 * @property string $status
 * @property null|string $remark
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
        'notify_url',
        'ip_whitelist',
        'status',
        'remark',
    ];

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
