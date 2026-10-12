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
 * 供应商商品覆盖的省份，* 表示全国。下单时号码所在省份要在这里面才会选到这个供应商商品。
 *
 * @property int $id
 * @property int $supplier_product_id
 * @property string $province
 */
class SupplierProductProvince extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'supplier_product_provinces';

    protected array $fillable = [
        'supplier_product_id',
        'province',
    ];
}
