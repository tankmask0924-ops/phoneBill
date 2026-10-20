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

namespace App\Service\Merchant;

use App\Dao\MerchantBalanceLogDao;
use App\Dao\MerchantDao;
use App\Exception\InsufficientBalanceException;
use App\Model\MerchantBalanceLog;
use App\Service\AbstractService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use RuntimeException;

/**
 * 商户余额的唯一入口：锁商户行 → 锁内重新读余额 → 改余额 → 写一条资金流水。
 *
 * 余额可以扣到负数，最低到「负的授信额度」（授信 0 就是不能负数）。
 *
 * 必须在调用方的事务里调用（和订单状态变更放在同一个事务，保证扣款 / 退款和状态一起成功或一起回滚）。
 */
class MerchantBalanceService extends AbstractService
{
    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantBalanceLogDao $balanceLogDao;

    /**
     * @param string $amount 变动金额，加为正、减为负，两位小数
     * @param bool $ignoreCredit 不受授信额度限制（订单失败改成功重新扣款：钱已经花在供应商那边，拦住账就对不上）
     * @throws InsufficientBalanceException 扣完会低于负的授信额度时
     */
    public function change(
        int $merchantId,
        string $type,
        string $amount,
        ?int $orderId = null,
        ?string $remark = null,
        ?int $adminUserId = null,
        bool $ignoreCredit = false
    ): MerchantBalanceLog {
        if (Db::transactionLevel() === 0) {
            throw new RuntimeException('改余额必须在事务里调用');
        }
        $merchant = $this->merchantDao->lockForUpdate($merchantId);
        if ($merchant === null) {
            throw new RuntimeException("商户 {$merchantId} 不存在");
        }
        $after = bcadd($merchant->balance, $amount, 2);
        $credit = (string) $merchant->credit_limit;
        // 只拦扣款：加款（还欠款）时即使还在授信线以下也照常加
        if (! $ignoreCredit && bccomp($amount, '0', 2) < 0 && bccomp($after, bcmul($credit, '-1', 2), 2) < 0) {
            throw new InsufficientBalanceException($merchant->balance, bcmul($amount, '-1', 2), bcadd($credit, '0', 2));
        }
        $this->merchantDao->newQuery()->where('id', $merchantId)->update(['balance' => $after]);

        return $this->balanceLogDao->create([
            'merchant_id' => $merchantId,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $after,
            'order_id' => $orderId,
            'remark' => $remark,
            'admin_user_id' => $adminUserId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
