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
 * 供应商商品支持的运营商。
 *
 * @property int $id
 * @property int $supplier_product_id
 * @property string $operator
 */
class SupplierProductOperator extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'supplier_product_operators';

    protected array $fillable = [
        'supplier_product_id',
        'operator',
    ];
}
