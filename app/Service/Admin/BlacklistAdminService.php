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

use App\Dao\AdminOperationLogDao;
use App\Dao\AdminUserDao;
use App\Dao\MobileBlacklistDao;
use App\Model\AdminUser;
use App\Model\MobileBlacklist;
use App\Service\AbstractService;
use App\Service\Mobile\MobileSegmentService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 风控 - 号码黑名单：命中的号码下单直接拒绝。
 */
class BlacklistAdminService extends AbstractService
{
    use ValidatesAdminInput;

    private const MODULE = 'risk';

    private const MAX_PER_PAGE = 100;

    private const MAX_BATCH = 500;

    #[Inject]
    protected MobileBlacklistDao $blacklistDao;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    #[Inject]
    protected MobileSegmentService $mobileSegmentService;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @param array<string, mixed> $query mobile（前缀匹配）
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));

        $builder = $this->blacklistDao->newQuery();
        $mobile = trim((string) ($query['mobile'] ?? ''));
        if ($mobile !== '' && ctype_digit($mobile)) {
            $builder->where('mobile', 'like', "{$mobile}%");
        }

        $total = (clone $builder)->count();
        $rows = $builder->orderByDesc('id')->forPage($page, $perPage)->get();
        $admins = $this->adminUserDao->newQuery()->whereIn('id', $rows->pluck('admin_user_id')->filter()->unique()->all())->pluck('real_name', 'id');

        return [
            'data' => $rows->map(static fn (MobileBlacklist $r) => [
                'id' => $r->id,
                'mobile' => $r->mobile,
                'reason' => $r->reason,
                'admin_name' => $r->admin_user_id ? ($admins[$r->admin_user_id] ?? null) : null,
                'created_at' => $r->created_at?->toDateTimeString(),
            ])->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 批量加入，已经在黑名单里的跳过。
     *
     * @param array<string, mixed> $data mobiles（数组，或逗号 / 换行分隔的字符串）/ reason
     * @return array{added: int, skipped: int}
     */
    public function create(AdminUser $operator, array $data, ?string $ip): array
    {
        $raw = $data['mobiles'] ?? null;
        $items = is_array($raw) ? $raw : preg_split('/[\s,，]+/u', is_string($raw) ? $raw : '');
        $mobiles = [];
        foreach ($items as $item) {
            $item = trim(is_string($item) || is_int($item) ? (string) $item : '');
            if ($item === '') {
                continue;
            }
            if (! $this->mobileSegmentService->isValidMobile($item)) {
                throw new HttpException(422, "「{$item}」不是 11 位手机号");
            }
            $mobiles[$item] = true;
        }
        if ($mobiles === []) {
            throw new HttpException(422, '请输入手机号');
        }
        if (count($mobiles) > self::MAX_BATCH) {
            throw new HttpException(422, '一次最多加 ' . self::MAX_BATCH . ' 个号码');
        }
        $reason = $this->remark($data['reason'] ?? null);

        $existing = $this->blacklistDao->newQuery()->whereIn('mobile', array_keys($mobiles))->pluck('mobile')->all();
        $new = array_values(array_diff(array_map('strval', array_keys($mobiles)), $existing));
        foreach ($new as $mobile) {
            $this->blacklistDao->create(['mobile' => $mobile, 'reason' => $reason, 'admin_user_id' => $operator->id]);
        }
        $this->operationLogDao->record($operator->id, self::MODULE, 'add_blacklist', 'mobile_blacklist', null, null, ['mobiles' => $new, 'reason' => $reason], $ip);

        return ['added' => count($new), 'skipped' => count($existing)];
    }

    public function delete(AdminUser $operator, int $id, ?string $ip): void
    {
        $row = $this->blacklistDao->find($id);
        if (! $row) {
            throw new HttpException(404, '记录不存在');
        }
        $this->blacklistDao->delete($row->id);
        $this->operationLogDao->record($operator->id, self::MODULE, 'remove_blacklist', 'mobile_blacklist', $row->id, ['mobile' => $row->mobile, 'reason' => $row->reason], null, $ip);
    }
}
