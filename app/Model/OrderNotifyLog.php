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
 * 每一次通知商户的记录，只写不改。
 *
 * @property int $id
 * @property int $order_id
 * @property int $attempt_no
 * @property string $url
 * @property string $payload
 * @property null|int $http_status
 * @property null|string $response
 * @property bool $success
 * @property Carbon $created_at
 */
class OrderNotifyLog extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'order_notify_logs';

    protected array $fillable = [
        'order_id',
        'attempt_no',
        'url',
        'payload',
        'http_status',
        'response',
        'success',
        'created_at',
    ];

    protected array $casts = [
        'success' => 'boolean',
    ];

    protected array $dates = [
        'created_at',
    ];
}
