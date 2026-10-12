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

use App\Model\MerchantFundingRequest;

class MerchantFundingRequestDao extends AbstractDao
{
    protected string $model = MerchantFundingRequest::class;

    public function find(int $id): ?MerchantFundingRequest
    {
        return MerchantFundingRequest::find($id);
    }

    /**
     * 带条件的状态变更，返回是否改成功。
     *
     * @param array<string, mixed> $attrs
     */
    public function transition(int $id, string $from, string $to, array $attrs = []): bool
    {
        return $this->newQuery()->where('id', $id)->where('status', $from)->update(['status' => $to] + $attrs) === 1;
    }
}
