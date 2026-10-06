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

use App\Service\Risk\BlacklistService;
use Carbon\Carbon;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\Model\Events\Deleted;
use Hyperf\Database\Model\Events\Saved;

/**
 * 号码黑名单，命中就拒绝下单。新增、删除时清掉这个号码的缓存（见 BlacklistService）。
 *
 * @property int $id
 * @property string $mobile
 * @property null|string $reason
 * @property null|int $admin_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MobileBlacklist extends Model
{
    protected ?string $table = 'mobile_blacklist';

    protected array $fillable = [
        'mobile',
        'reason',
        'admin_user_id',
    ];

    public function saved(Saved $event): void
    {
        ApplicationContext::getContainer()->get(BlacklistService::class)->forget($this->mobile);
    }

    public function deleted(Deleted $event): void
    {
        ApplicationContext::getContainer()->get(BlacklistService::class)->forget($this->mobile);
    }
}
