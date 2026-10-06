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
use App\Dao\MerchantUserDao;
use App\Model\AdminUser;
use App\Model\MerchantUser;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户中心 - 商户后台账号：平台给商户开账号、重置密码、启用禁用。
 *
 * 重置密码、禁用后该账号之前的登录立即失效（见 MerchantAuthMiddleware）。
 */
class MerchantUserAdminService extends AbstractService
{
    use ValidatesAdminInput;

    private const MODULE = 'merchant';

    #[Inject]
    protected MerchantAdminService $merchantAdminService;

    #[Inject]
    protected MerchantUserDao $merchantUserDao;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $merchantId): array
    {
        $this->merchantAdminService->findOrFail($merchantId);

        return $this->merchantUserDao->newQuery()->where('merchant_id', $merchantId)->orderBy('id')->get()
            ->map(fn (MerchantUser $u) => $this->format($u))->values()->all();
    }

    /**
     * @param array<string, mixed> $data username / password / real_name
     * @return array<string, mixed>
     */
    public function create(AdminUser $operator, int $merchantId, array $data, ?string $ip): array
    {
        $merchant = $this->merchantAdminService->findOrFail($merchantId);
        $username = trim((string) ($data['username'] ?? ''));
        if (! preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $username)) {
            throw new HttpException(422, '账号为 3~64 位字母、数字或 _ . @ -');
        }
        if ($this->merchantUserDao->findByUsername($username)) {
            throw new HttpException(422, "账号「{$username}」已存在");
        }
        $password = (string) ($data['password'] ?? '');
        if ($password === '') {
            throw new HttpException(422, '请设置初始密码');
        }
        $realName = trim((string) ($data['real_name'] ?? ''));

        $user = $this->merchantUserDao->create([
            'merchant_id' => $merchant->id,
            'username' => $username,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'real_name' => $realName === '' ? null : mb_substr($realName, 0, 64),
            'status' => 'active',
        ]);
        $after = $this->format($user);
        $this->operationLogDao->record($operator->id, self::MODULE, 'create_merchant_user', 'merchant', $merchant->id, null, $after, $ip);

        return $after;
    }

    /**
     * @return array<string, mixed>
     */
    public function changeStatus(AdminUser $operator, int $merchantId, int $userId, mixed $status, ?string $ip): array
    {
        $status = $this->status($status);
        $user = $this->findOrFail($merchantId, $userId);
        if ($status !== $user->status) {
            $this->merchantUserDao->update($user->id, ['status' => $status]);
            $this->operationLogDao->record(
                $operator->id,
                self::MODULE,
                $status === 'active' ? 'enable_merchant_user' : 'disable_merchant_user',
                'merchant',
                $merchantId,
                ['username' => $user->username, 'status' => $user->status],
                ['username' => $user->username, 'status' => $status],
                $ip
            );
        }

        return $this->format($this->findOrFail($merchantId, $userId));
    }

    public function resetPassword(AdminUser $operator, int $merchantId, int $userId, mixed $password, ?string $ip): void
    {
        $user = $this->findOrFail($merchantId, $userId);
        if (! is_string($password) || $password === '') {
            throw new HttpException(422, '请输入新密码');
        }
        $this->merchantUserDao->update($user->id, ['password' => password_hash($password, PASSWORD_BCRYPT)]);
        // 不记密码本身
        $this->operationLogDao->record($operator->id, self::MODULE, 'reset_merchant_user_password', 'merchant', $merchantId, null, ['username' => $user->username], $ip);
    }

    private function findOrFail(int $merchantId, int $userId): MerchantUser
    {
        $user = $this->merchantUserDao->find($userId);
        if (! $user || $user->merchant_id !== $merchantId) {
            throw new HttpException(404, '账号不存在');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(MerchantUser $user): array
    {
        return [
            'id' => $user->id,
            'merchant_id' => $user->merchant_id,
            'username' => $user->username,
            'real_name' => $user->real_name,
            'status' => $user->status,
            'last_login_at' => $user->last_login_at?->toDateTimeString(),
            'created_at' => $user->created_at?->toDateTimeString(),
        ];
    }
}
