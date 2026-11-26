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

namespace App\Service\Admin;

use App\Dao\MerchantDao;
use App\Dao\SecurityLogDao;
use App\Model\SecurityLog;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 系统设置 - 登录日志：两端的登录成功 / 失败、改密码、商户自助重置密钥 / 改 IP 白名单 / 批量下单，只读。
 */
class SecurityLogAdminService extends AbstractService
{
    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected SecurityLogDao $securityLogDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    /**
     * @param array<string, mixed> $query portal / event / username / ip / merchant_id / created_from / created_to（Y-m-d）/ page / per_page
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));

        $filters = [];
        foreach (['portal', 'event', 'username', 'ip'] as $field) {
            $value = is_string($query[$field] ?? null) ? trim($query[$field]) : '';
            if ($value !== '') {
                $filters[$field] = $value;
            }
        }
        if (isset($query['merchant_id']) && is_numeric($query['merchant_id'])) {
            $filters['merchant_id'] = (int) $query['merchant_id'];
        }
        if ($this->isDate($query['created_from'] ?? null)) {
            $filters['created_from'] = $query['created_from'] . ' 00:00:00';
        }
        if ($this->isDate($query['created_to'] ?? null)) {
            $filters['created_to'] = $query['created_to'] . ' 23:59:59';
        }

        [$logs, $total] = $this->securityLogDao->paginate($filters, $page, $perPage);
        $merchants = $this->merchantDao->newQuery()
            ->whereIn('id', $logs->pluck('merchant_id')->filter()->unique()->values()->all())
            ->get(['id', 'name'])
            ->keyBy('id');

        return [
            'data' => $logs->map(fn (SecurityLog $log) => [
                'id' => $log->id,
                'portal' => $log->portal,
                'event' => $log->event,
                'user_id' => $log->user_id,
                'username' => $log->username,
                'merchant_id' => $log->merchant_id,
                'merchant_name' => $log->merchant_id === null ? null : $merchants->get($log->merchant_id)?->name,
                'ip' => $log->ip,
                'user_agent' => $log->user_agent,
                'detail' => $log->detail,
                'created_at' => $log->created_at?->toDateTimeString(),
            ])->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
