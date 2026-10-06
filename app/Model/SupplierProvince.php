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

/**
 * 供应商覆盖的省份，province 为 * 表示全国。
 *
 * @property int $id
 * @property int $supplier_id
 * @property string $province
 */
class SupplierProvince extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'supplier_provinces';

    protected array $fillable = [
        'supplier_id',
        'province',
    ];
}
