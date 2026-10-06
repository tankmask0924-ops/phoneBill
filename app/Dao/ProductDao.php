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

namespace App\Dao;

use App\Model\Product;

class ProductDao extends AbstractDao
{
    protected string $model = Product::class;

    public function find(int $id): ?Product
    {
        return Product::find($id);
    }
}
