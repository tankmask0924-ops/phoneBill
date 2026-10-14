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
 * 商户后台的导出记录，文件见 MerchantExportService。
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $merchant_user_id
 * @property string $type
 * @property array $filters
 * @property string $status
 * @property string $file_name
 * @property null|string $file_path
 * @property null|int $row_count
 * @property null|string $error
 * @property null|Carbon $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MerchantExport extends Model
{
    public const TYPE_ORDERS = 'orders';

    public const TYPE_BALANCE_LOGS = 'balance_logs';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected ?string $table = 'merchant_exports';

    protected array $fillable = [
        'merchant_id',
        'merchant_user_id',
        'type',
        'filters',
        'status',
        'file_name',
        'file_path',
        'row_count',
        'error',
        'finished_at',
    ];

    protected array $casts = [
        'filters' => 'array',
        'row_count' => 'integer',
        'finished_at' => 'datetime',
    ];
}
