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

use App\Model\MerchantExport;
use Hyperf\Database\Model\Collection;

class MerchantExportDao extends AbstractDao
{
    protected string $model = MerchantExport::class;

    public function find(int $id): ?MerchantExport
    {
        return MerchantExport::find($id);
    }

    public function findForMerchant(int $merchantId, int $id): ?MerchantExport
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->where('id', $id)->first();
    }

    public function countPending(int $merchantId): int
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->where('status', MerchantExport::STATUS_PENDING)->count();
    }

    /**
     * 只有生成中的才改，返回是否改成功。
     *
     * @param array<string, mixed> $attrs
     */
    public function finish(int $id, array $attrs): bool
    {
        return $this->newQuery()->where('id', $id)->where('status', MerchantExport::STATUS_PENDING)->update($attrs) === 1;
    }

    /**
     * 创建时间早于 $before 的记录。
     *
     * @return Collection<int, MerchantExport>
     */
    public function createdBefore(string $before, int $limit): Collection
    {
        return $this->newQuery()->where('created_at', '<', $before)->orderBy('id')->limit($limit)->get();
    }
}
