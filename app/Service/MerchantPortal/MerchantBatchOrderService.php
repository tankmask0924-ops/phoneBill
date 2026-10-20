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

use App\Dao\MerchantDao;
use App\Exception\OpenApiException;
use App\Model\Merchant;
use App\Model\MerchantUser;
use App\Service\AbstractService;
use App\Service\Order\OrderService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Logger\LoggerFactory;

/**
 * 商户后台批量下单（docs/project.md 5.1）：一批一个商品、多个手机号，逐个走开放接口同一套下单逻辑（OrderService）。
 *
 * - 先预览：每个号码的运营商、价格或不能下单的原因（含同一批里重复、和进行中的订单重复），加上合计金额和当前余额，不扣款；
 * - 提交时商户订单号按「批次号-序号」生成，批次号由前端每批生成一次：同一批重复提交（比如超时后再点）
 *   返回之前建好的订单，不重复扣款；
 * - 每个号码单独下单、单独扣款，某个号码失败（余额不足、重复充值等）不影响其他号码，结果逐条返回；
 * - 结果通知照常推到商户配置的通知地址。
 */
class MerchantBatchOrderService extends AbstractService
{
    /** 一批最多的号码数：提交是同步逐个下单，太多会超时 */
    public const MAX_MOBILES = 200;

    #[Inject]
    protected OrderService $orderService;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @param array<string, mixed> $params product_code、mobiles
     * @return array<string, mixed>
     */
    public function preview(Merchant $merchant, array $params): array
    {
        $this->assertActive($merchant);
        [$productCode, $mobiles] = $this->input($params);

        $items = [];
        $seen = [];
        $faceValue = null;
        foreach ($mobiles as $mobile) {
            $item = ['mobile' => $mobile, 'ok' => false, 'operator' => null, 'province' => null, 'price' => null, 'error' => null];
            if (isset($seen[$mobile])) {
                // 同一号码同一面值同时只能有一笔进行中的订单，同一批里重复的号码提交时一定会被拒
                $items[] = array_replace($item, ['error' => '号码重复（同一批里出现多次），同一号码同一面值同时只能有一笔订单']);

                continue;
            }
            $seen[$mobile] = true;
            try {
                $checked = $this->orderService->check($merchant, $productCode, $mobile);
            } catch (OpenApiException $e) {
                $items[] = array_replace($item, ['error' => $this->reason($e)]);

                continue;
            }
            $items[] = array_replace($item, ['ok' => true, 'operator' => $checked['operator'], 'province' => $checked['province'], 'price' => $checked['price']]);
            $faceValue = (int) $checked['quote']['face_value'];
        }

        // 和之前的订单重复：同一面值在防重窗口内还有进行中的订单，提交时会被拒，一次查出来提前标上
        $okMobiles = array_column(array_filter($items, static fn (array $i) => $i['ok']), 'mobile');
        $duplicates = $faceValue === null ? [] : $this->orderService->duplicateMobiles($okMobiles, $faceValue);
        $total = '0.00';
        foreach ($items as &$row) {
            if ($row['ok'] && isset($duplicates[$row['mobile']])) {
                $row = array_replace($row, ['ok' => false, 'error' => $duplicates[$row['mobile']]]);
            }
            if ($row['ok']) {
                $total = bcadd($total, $row['price'], 2);
            }
        }
        unset($row);

        return [
            'items' => $items,
            'valid_count' => count(array_filter($items, static fn (array $i) => $i['ok'])),
            'total_amount' => $total,
            // 登录态里的商户可能是旧的，余额查最新的；够不够下单看可用额度（余额 + 授信）
        ] + $this->balances($merchant);
    }

    /**
     * @param array<string, mixed> $params product_code、mobiles、batch_no
     * @return array<string, mixed>
     */
    public function submit(Merchant $merchant, MerchantUser $user, array $params): array
    {
        $this->assertActive($merchant);
        [$productCode, $mobiles] = $this->input($params);
        $batchNo = is_string($params['batch_no'] ?? null) ? trim($params['batch_no']) : '';
        if (preg_match('/^[A-Za-z0-9]{8,40}$/', $batchNo) !== 1) {
            throw new HttpException(422, '批次号不正确，请刷新页面重试');
        }

        $items = [];
        foreach ($mobiles as $i => $mobile) {
            $merchantOrderNo = $batchNo . '-' . sprintf('%03d', $i + 1);
            $item = ['mobile' => $mobile, 'merchant_order_no' => $merchantOrderNo, 'ok' => false, 'order_no' => null, 'status' => null, 'amount' => null, 'error' => null];
            try {
                $order = $this->orderService->create($merchant, [
                    'product_code' => $productCode,
                    'mobile' => $mobile,
                    'merchant_order_no' => $merchantOrderNo,
                ]);
            } catch (OpenApiException $e) {
                $items[] = array_replace($item, ['error' => $this->reason($e)]);

                continue;
            }
            if ($order->mobile !== $mobile || $order->product_code !== $productCode) {
                // 这个商户订单号之前建过别的号码 / 商品的单：批次号被重复用了，不能把旧单当成这次的结果
                $items[] = array_replace($item, ['error' => '批次号已用过，请刷新页面后重新提交']);

                continue;
            }
            $presented = $this->orderService->present($order);
            $items[] = array_replace($item, ['ok' => true, 'order_no' => $order->order_no, 'status' => $presented['status'], 'amount' => $presented['amount']]);
        }
        $successCount = count(array_filter($items, static fn (array $i) => $i['ok']));
        $this->loggerFactory->get('merchant')->info('商户后台批量下单', [
            'merchant_id' => $merchant->id,
            'merchant_user_id' => $user->id,
            'batch_no' => $batchNo,
            'product_code' => $productCode,
            'total' => count($items),
            'success' => $successCount,
        ]);

        return [
            'batch_no' => $batchNo,
            'items' => $items,
            'success_count' => $successCount,
            'failed_count' => count($items) - $successCount,
        ] + $this->balances($merchant);
    }

    /**
     * 开放接口的提示里带参数名（mobile 必须是 11 位手机号），商户后台换成页面上的说法。
     */
    private function reason(OpenApiException $e): string
    {
        return $e->errorCode === OpenApiException::INVALID_PARAMS ? '手机号格式不对，要 11 位手机号' : $e->getMessage();
    }

    /**
     * 商户后台停用的商户也能登录看数据，但不能下单。
     */
    private function assertActive(Merchant $merchant): void
    {
        if ($this->merchantDao->find($merchant->id)?->status !== 'active') {
            throw new HttpException(422, '商户已停用，不能下单');
        }
    }

    /**
     * 号码去掉首尾空白和空行，保留顺序和重复（重复的在结果里标出来）。
     *
     * @param array<string, mixed> $params
     * @return array{0: string, 1: list<string>}
     */
    private function input(array $params): array
    {
        $productCode = is_string($params['product_code'] ?? null) ? trim($params['product_code']) : '';
        if ($productCode === '') {
            throw new HttpException(422, '请选择商品');
        }
        $mobiles = is_array($params['mobiles'] ?? null) ? $params['mobiles'] : [];
        $mobiles = array_values(array_filter(
            array_map(static fn ($m) => is_string($m) || is_int($m) ? trim((string) $m) : '', $mobiles),
            static fn (string $m) => $m !== '',
        ));
        if ($mobiles === []) {
            throw new HttpException(422, '请填写手机号');
        }
        if (count($mobiles) > self::MAX_MOBILES) {
            throw new HttpException(422, '一批最多 ' . self::MAX_MOBILES . ' 个号码，请分批提交');
        }

        return [$productCode, $mobiles];
    }

    /**
     * @return array{balance: null|string, credit_limit: null|string, available_balance: null|string}
     */
    private function balances(Merchant $merchant): array
    {
        $fresh = $this->merchantDao->find($merchant->id);

        return ['balance' => $fresh?->balance, 'credit_limit' => $fresh?->credit_limit, 'available_balance' => $fresh?->availableBalance()];
    }
}
