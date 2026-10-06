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

use App\Service\Mobile\MobileSegmentService;
use Carbon\Carbon;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\Model\Events\Deleted;
use Hyperf\Database\Model\Events\Saved;

/**
 * 号段库：手机号前 7 位 → 运营商、省份、城市。保存、删除时清掉这个号段的缓存（见 MobileSegmentService）。
 *
 * @property int $id
 * @property string $segment
 * @property string $operator
 * @property string $province
 * @property null|string $city
 * @property bool $is_virtual
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MobileSegment extends Model
{
    protected ?string $table = 'mobile_segments';

    protected array $fillable = [
        'segment',
        'operator',
        'province',
        'city',
        'is_virtual',
    ];

    protected array $casts = [
        'is_virtual' => 'boolean',
    ];

    public function saved(Saved $event): void
    {
        ApplicationContext::getContainer()->get(MobileSegmentService::class)->forget($this->segment);
    }

    public function deleted(Deleted $event): void
    {
        ApplicationContext::getContainer()->get(MobileSegmentService::class)->forget($this->segment);
    }
}
