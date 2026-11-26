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

use App\Model\SecurityLog;
use Hyperf\Database\Model\Collection;

class SecurityLogDao extends AbstractDao
{
    protected string $model = SecurityLog::class;

    /**
     * @param array<string, mixed> $attributes
     */
    public function record(array $attributes): SecurityLog
    {
        return $this->newQuery()->create($attributes + ['created_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * @param array{portal?: string, event?: string, username?: string, ip?: string, merchant_id?: int, user_id?: int, created_from?: string, created_to?: string} $filters 时间是 Y-m-d H:i:s
     * @return array{0: Collection<int, SecurityLog>, 1: int} 这一页、总条数
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $builder = $this->newQuery();
        foreach (['portal', 'event', 'username', 'ip', 'merchant_id', 'user_id'] as $field) {
            if (isset($filters[$field])) {
                $builder->where($field, $filters[$field]);
            }
        }
        if (isset($filters['created_from'])) {
            $builder->where('created_at', '>=', $filters['created_from']);
        }
        if (isset($filters['created_to'])) {
            $builder->where('created_at', '<=', $filters['created_to']);
        }
        $total = (clone $builder)->count();

        return [$builder->orderByDesc('id')->forPage($page, $perPage)->get(), $total];
    }
}
