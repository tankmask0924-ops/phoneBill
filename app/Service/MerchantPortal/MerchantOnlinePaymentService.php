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
use App\Service\Storage\ObjectStorageService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 商户后台 - 支付宝转账加款：看平台的固定收款码，转账后提交支付宝订单号和金额（立即查到账）、没到账的重新查询、取消、查记录。
 * 规则见 AlipayTransferService。
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
    protected ObjectStorageService $storage;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 有没有开通（平台给这个商户指定了启用的账户）、金额范围、收款码（临时链接，取不到时 qr_code_url 为 null）、收款账号。
     *
     * @return array{enabled: bool, min_amount: null|string, max_amount: null|string, qr_code_url: null|string, receive_account: null|string, receive_name: null|string}
     */
    public function options(Merchant $merchant): array
    {
        $account = $this->transferService->merchantAccount($merchant->id);
        $qrCodeUrl = null;
        if ($account?->qr_code !== null) {
            try {
                $qrCodeUrl = $this->storage->url($account->qr_code);
            } catch (Throwable) {
                // 存储没配好时只显示收款账号，商户手动转账
            }
        }

        return [
            'enabled' => $account !== null,
            'min_amount' => $account?->min_amount,
            'max_amount' => $account?->max_amount,
            'qr_code_url' => $qrCodeUrl,
            'receive_account' => $account?->account,
            'receive_name' => $account === null ? null : self::maskName($account->account_name),
        ];
    }

    /**
     * 提交转账并立即查询到账（同一个订单号没到账的再提交就是改金额重新查）。
     *
     * @param array<string, mixed> $data alipay_order_no / amount
     * @return array<string, mixed>
     */
    public function create(Merchant $merchant, MerchantUser $user, array $data): array
    {
        $alipayOrderNo = trim(is_scalar($data['alipay_order_no'] ?? null) ? (string) $data['alipay_order_no'] : '');
        if (preg_match('/^[0-9A-Za-z]{10,64}$/', $alipayOrderNo) !== 1) {
            throw new HttpException(422, '支付宝订单号格式不对，在支付宝「账单」里点开这笔转账，复制「订单号」');
        }
        $amount = $this->money($data['amount'] ?? null, '转账金额');
        $p = $this->transferService->submit($merchant->id, $user->id, $alipayOrderNo, $amount);
        $this->log('商户提交支付宝转账加款', $merchant, $user, ['order_no' => $p->order_no, 'alipay_order_no' => $alipayOrderNo, 'amount' => $amount, 'status' => $p->status]);

        return $this->format($p);
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
     * 「重新查询」：未到账的单再查一次支付宝，结果在 status / check_message 里。
     *
     * @return array<string, mixed>
     */
    public function check(Merchant $merchant, int $id): array
    {
        return $this->format($this->transferService->check($this->find($merchant, $id)));
    }

    /**
     * 只能取消未到账的单（比如订单号填错了）。取消后再提交同一个订单号会重新打开这笔单。
     *
     * @return array<string, mixed>
     */
    public function cancel(Merchant $merchant, MerchantUser $user, int $id): array
    {
        $p = $this->find($merchant, $id);
        if (! $this->paymentDao->transition($p->id, MerchantOnlinePayment::STATUS_PENDING, MerchantOnlinePayment::STATUS_CANCELLED)) {
            throw new HttpException(422, '这笔单已经不是未到账状态了');
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
     * 不返回流水号、付款方账户等平台内部信息。
     *
     * @return array<string, mixed>
     */
    private function format(MerchantOnlinePayment $p): array
    {
        return [
            'id' => $p->id,
            'order_no' => $p->order_no,
            'alipay_order_no' => $p->alipay_order_no,
            'amount' => $p->amount,
            'pay_amount' => $p->pay_amount,
            'receive_account' => $p->receive_account,
            'receive_name' => self::maskName($p->receive_name),
            'status' => $p->status,
            'checked_at' => $p->checked_at?->toDateTimeString(),
            'check_message' => $p->status === MerchantOnlinePayment::STATUS_PENDING ? $p->check_message : null,
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
