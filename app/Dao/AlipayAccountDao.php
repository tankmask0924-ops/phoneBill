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

use App\Model\AlipayAccount;

class AlipayAccountDao extends AbstractDao
{
    protected string $model = AlipayAccount::class;

    public function find(int $id): ?AlipayAccount
    {
        return AlipayAccount::find($id);
    }

    /**
     * @param list<int> $ids
     * @return array<int, string> id => 账户名称
     */
    public function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : $this->newQuery()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
