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

namespace App\Service\MerchantPortal;

use App\Dao\MerchantOnlinePaymentDao;
use App\Model\Merchant;
use App\Model\MerchantOnlinePayment;
use App\Model\MerchantUser;
use App\Service\AbstractService;
use App\Service\Admin\ValidatesAdminInput;
use App\Service\FiltersByDateRange;
use App\Service\Payment\AlipayTransferService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 商户后台 - 支付宝扫码转账加款：发起（拿二维码和应付金额）、「我已付款」立即核对、取消、查记录。规则见 AlipayTransferService。
 * 所有查询都按当前登录商户过滤。
 */
class MerchantOnlinePaymentService extends AbstractService
{
    use FiltersByDateRange;
    use ValidatesAdminInput;

    private const MAX_PER_PAGE = 100;

    private const STATUSES = [MerchantOnlinePayment::STATUS_PENDING, MerchantOnlinePayment::STATUS_PAID, MerchantOnlinePayment::STATUS_EXPIRED, MerchantOnlinePayment::STATUS_CANCELLED];

    #[Inject]
    protected AlipayTransferService $transferService;

    #[Inject]
    protected MerchantOnlinePaymentDao $paymentDao;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 有没有开通、金额范围、二维码有效期。
     *
     * @return array{enabled: bool, min_amount: null|string, max_amount: null|string, expire_minutes: null|int}
     */
    public function options(): array
    {
        $config = $this->transferService->enabledConfig();

        return [
            'enabled' => $config !== null,
            'min_amount' => $config['min_amount'] ?? null,
            'max_amount' => $config['max_amount'] ?? null,
            'expire_minutes' => $config['expire_minutes'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $data amount
     * @return array<string, mixed>
     */
    public function create(Merchant $merchant, MerchantUser $user, array $data): array
    {
        $amount = $this->money($data['amount'] ?? null, '加款金额');
        $p = $this->transferService->create($merchant->id, $user->id, $amount);
        $this->log('商户发起支付宝转账加款', $merchant, $user, ['order_no' => $p->order_no, 'amount' => $amount, 'pay_amount' => $p->pay_amount]);

        return $this->format($this->find($merchant, $p->id));
    }

    /**
     * @param array<string, mixed> $query status / created_from / created_to
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(Merchant $merchant, array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));
        $builder = $this->paymentDao->newQuery()->where('merchant_id', $merchant->id);
        $status = $query['status'] ?? null;
        if (in_array($status, self::STATUSES, true)) {
            $builder->where('status', $status);
        }
        $this->applyDateRange($builder, $query, $status === MerchantOnlinePayment::STATUS_PENDING);
        $total = (clone $builder)->count();
        $rows = $builder->orderByDesc('id')->forPage($page, $perPage)->get();

        return [
            'data' => $rows->map(fn (MerchantOnlinePayment $p) => $this->format($p))->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Merchant $merchant, int $id): array
    {
        return $this->format($this->find($merchant, $id));
    }

    /**
     * 「我已付款」：待支付的单立即核对一次（查询失败不报错，之后每分钟还会自动核对）。
     *
     * @return array<string, mixed>
     */
    public function check(Merchant $merchant, int $id): array
    {
        $p = $this->find($merchant, $id);
        if ($p->status === MerchantOnlinePayment::STATUS_PENDING) {
            try {
                $this->transferService->checkNow();
            } catch (Throwable) {
                // 已记日志，等定时核对
            }
        }

        return $this->format($this->find($merchant, $id));
    }

    /**
     * 只能取消待支付的单。取消后金额要过了核对期才释放，万一已经付了款，后台还能找到这笔到账确认入账。
     *
     * @return array<string, mixed>
     */
    public function cancel(Merchant $merchant, MerchantUser $user, int $id): array
    {
        $p = $this->find($merchant, $id);
        if (! $this->paymentDao->transition($p->id, MerchantOnlinePayment::STATUS_PENDING, MerchantOnlinePayment::STATUS_CANCELLED)) {
            throw new HttpException(422, '这笔单已经不是待支付状态了');
        }
        $this->log('商户取消支付宝转账加款', $merchant, $user, ['order_no' => $p->order_no]);

        return $this->format($this->find($merchant, $id));
    }

    private function find(Merchant $merchant, int $id): MerchantOnlinePayment
    {
        $p = $this->paymentDao->findForMerchant($merchant->id, $id);
        if ($p === null) {
            throw new HttpException(404, '支付单不存在');
        }

        return $p;
    }

    /**
     * 不返回流水号、付款方账户等平台内部信息。待支付的单带二维码内容。
     *
     * @return array<string, mixed>
     */
    private function format(MerchantOnlinePayment $p): array
    {
        $pending = $p->status === MerchantOnlinePayment::STATUS_PENDING;

        return [
            'id' => $p->id,
            'order_no' => $p->order_no,
            'amount' => $p->amount,
            'pay_amount' => $p->pay_amount,
            'receive_account' => $p->receive_account,
            'receive_name' => self::maskName($p->receive_name),
            'status' => $p->status,
            'expires_at' => $p->expires_at->toDateTimeString(),
            // 二维码到期后还在核对期内，仍是待支付，只是不再展示二维码
            'expired_qr' => $pending && $p->expires_at->getTimestamp() < time(),
            'qr_content' => $pending ? AlipayTransferService::transferUri($p) : null,
            'paid_at' => $p->paid_at?->toDateTimeString(),
            'created_at' => $p->created_at?->toDateTimeString(),
        ];
    }

    /**
     * 姓名只露最后一个字，如「*三」。
     */
    private static function maskName(string $name): string
    {
        $length = mb_strlen($name);

        return $length <= 1 ? $name : str_repeat('*', $length - 1) . mb_substr($name, -1);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $message, Merchant $merchant, MerchantUser $user, array $context): void
    {
        $this->loggerFactory->get('merchant')->info($message, ['merchant_id' => $merchant->id, 'user' => $user->username] + $context);
    }
}
