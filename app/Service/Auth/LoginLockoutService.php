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

namespace App\Service\Auth;

use App\Dao\AdminUserDao;
use App\Dao\MerchantUserDao;
use App\Model\SecurityLog;
use App\Service\AbstractService;
use App\Service\Security\SecurityLogService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Logger\LoggerFactory;

/**
 * 登录密码连续输错封禁：管理后台、商户后台共用。
 *
 * - 同一账号连续输错 MAX_FAILURES 次，账号自动改成禁用（封禁），之后密码对了也登不上；
 * - 登录成功（账号密码都对、账号启用中）清零；后台重新启用账号、命令行 admin:unlock 解封时也清零；
 * - 不存在的用户名不计数，前 4 次和用户名不存在一样只提示「账号或密码错误」，防止枚举账号；
 * - 每次输错都记一条安全日志（reason=wrong_password，带第几次、这次是否封禁）。
 */
class LoginLockoutService extends AbstractService
{
    public const MAX_FAILURES = 5;

    private const GENERIC_LOGIN_FAIL_MESSAGE = '账号或密码错误';

    #[Inject]
    protected LoggerFactory $loggerFactory;

    #[Inject]
    protected SecurityLogService $securityLogService;

    /**
     * 密码输错：记一次，满次数就封禁。一定抛异常：没满 401，满了 403。
     *
     * @param string $channel 哪一端（SecurityLog::PORTAL_*），也是封禁时写日志的渠道
     * @param string $contact 封禁提示里让用户找谁解封
     * @param null|int $merchantId 商户后台账号所属商户，记安全日志用
     */
    public function recordFailure(AdminUserDao|MerchantUserDao $dao, int $userId, string $username, string $channel, string $contact, ?int $merchantId = null): never
    {
        $failures = $dao->incrementLoginFailures($userId);
        $locked = $failures >= self::MAX_FAILURES && $dao->lockAfterLoginFailures($userId, self::MAX_FAILURES);
        $this->securityLogService->loginFailed($channel, $userId, $username, $merchantId, SecurityLog::REASON_WRONG_PASSWORD, ['failures' => $failures, 'locked' => $locked]);
        if ($failures < self::MAX_FAILURES) {
            throw new HttpException(401, self::GENERIC_LOGIN_FAIL_MESSAGE);
        }
        if ($locked) {
            $this->loggerFactory->get($channel)->warning('登录密码连续输错，账号已自动封禁', ['user_id' => $userId, 'user' => $username, 'failures' => $failures]);
        }

        throw new HttpException(403, '密码连续输错 ' . self::MAX_FAILURES . ' 次，账号已被封禁，请联系' . $contact);
    }
}
