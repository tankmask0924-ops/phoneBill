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
 * 供应商。config 是加密后的接口参数，读写都通过 SupplierAdminService，不要直接用。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $driver
 * @property null|string $config
 * @property string $status
 * @property null|string $remark
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Supplier extends Model
{
    protected ?string $table = 'suppliers';

    protected array $fillable = [
        'name',
        'code',
        'driver',
        'config',
        'status',
        'remark',
    ];
}
