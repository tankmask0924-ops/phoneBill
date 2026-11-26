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

namespace App\Service\Security;

use App\Dao\SecurityLogDao;
use App\Model\SecurityLog;
use App\Network\ClientIpResolver;
use App\Service\AbstractService;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * 写登录与账号安全日志（security_logs），管理后台、商户后台共用。
 *
 * - IP、User-Agent 从当前请求里取（IP 规则同操作日志，见 ClientIpResolver），调用方不用传；
 * - 写库失败只记一条 error 日志，不抛异常：日志出问题不能让人登不上；
 * - 不记密码、验证码、密钥本身。
 */
class SecurityLogService extends AbstractService
{
    private const USERNAME_MAX = 64;

    private const USER_AGENT_MAX = 255;

    #[Inject]
    protected SecurityLogDao $securityLogDao;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @param null|array<string, mixed> $detail
     */
    public function record(string $portal, string $event, ?int $userId, string $username, ?int $merchantId = null, ?array $detail = null): void
    {
        [$ip, $userAgent] = $this->origin();
        try {
            $this->securityLogDao->record([
                'portal' => $portal,
                'event' => $event,
                'user_id' => $userId,
                'username' => mb_substr($username, 0, self::USERNAME_MAX),
                'merchant_id' => $merchantId,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'detail' => $detail,
            ]);
        } catch (Throwable $e) {
            $this->loggerFactory->get('security')->error('安全日志写入失败', [
                'portal' => $portal,
                'event' => $event,
                'user' => $username,
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 登录失败，reason 用 SecurityLog::REASON_*。
     *
     * @param array<string, mixed> $extra 附加到 detail 里的信息
     */
    public function loginFailed(string $portal, ?int $userId, string $username, ?int $merchantId, string $reason, array $extra = []): void
    {
        $this->record($portal, SecurityLog::EVENT_LOGIN_FAILED, $userId, $username, $merchantId, ['reason' => $reason] + $extra);
    }

    /**
     * @return array{0: null|string, 1: null|string} IP、User-Agent；不在 HTTP 请求里（命令行、队列）时都是 null
     */
    private function origin(): array
    {
        if (! Context::has(ServerRequestInterface::class)) {
            return [null, null];
        }
        $userAgent = trim($this->request->getHeaderLine('User-Agent'));

        return [
            $this->clientIpResolver->resolve($this->request),
            $userAgent === '' ? null : mb_substr($userAgent, 0, self::USER_AGENT_MAX),
        ];
    }
}
