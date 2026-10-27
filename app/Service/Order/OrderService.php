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

namespace App\Service\Order;

use App\Dao\OrderDao;
use App\Exception\InsufficientBalanceException;
use App\Exception\OpenApiException;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Service\AbstractService;
use App\Service\Merchant\MerchantBalanceService;
use App\Service\Merchant\MerchantPriceService;
use App\Service\Mobile\MobileSegmentService;
use App\Service\Product\CategoryMobileLimitService;
use App\Service\Product\ProductRouteService;
use App\Service\Risk\BlacklistService;
use App\Service\Setting\OrderSettingService;
use App\Support\RedisLock;
use Hyperf\Database\Exception\QueryException;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;

/**
 * 商户下单（docs/project.md 3.1）：校验 → 识别号码 → 定价 → 选路预检 → 扣款建单 → 推进队列。
 *
 * 任何一步不通过都直接拒绝、不建单、不扣款。同一个商户订单号重复提交返回原订单。
 */
class OrderService extends AbstractService
{
    /** 还没出结果的状态，重复充值拦截看这些 */
    public const IN_PROGRESS = [Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL, Order::STATUS_TIMEOUT];

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected MerchantPriceService $priceService;

    #[Inject]
    protected BlacklistService $blacklistService;

    #[Inject]
    protected MobileSegmentService $mobileSegmentService;

    #[Inject]
    protected ProductRouteService $productRouteService;

    #[Inject]
    protected MerchantBalanceService $balanceService;

    #[Inject]
    protected OrderDispatcher $dispatcher;

    #[Inject]
    protected RedisLock $lock;

    #[Inject]
    protected OrderNoGenerator $orderNoGenerator;

    #[Inject]
    protected OrderSettingService $orderSettingService;

    #[Inject]
    protected CategoryMobileLimitService $categoryLimitService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @param array<string, mixed> $params product_code / mobile / merchant_order_no / notify_url（可选）
     */
    public function create(Merchant $merchant, array $params): Order
    {
        $merchantOrderNo = $this->string($params, 'merchant_order_no');
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $merchantOrderNo) !== 1) {
            throw new OpenApiException(OpenApiException::INVALID_PARAMS, 'merchant_order_no 为 1~64 位字母、数字、_ 或 -');
        }
        $existing = $this->orderDao->findByMerchantOrderNo($merchant->id, $merchantOrderNo);
        if ($existing !== null) {
            return $existing;
        }

        $mobile = $this->string($params, 'mobile');
        $notifyUrl = $this->string($params, 'notify_url');
        if ($notifyUrl !== '' && (mb_strlen($notifyUrl) > 500 || filter_var($notifyUrl, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#i', $notifyUrl))) {
            throw new OpenApiException(OpenApiException::INVALID_PARAMS, 'notify_url 必须是 http:// 或 https:// 开头的网址');
        }
        ['quote' => $quote, 'operator' => $operator, 'segment_operator' => $segmentOperator, 'province' => $province, 'price' => $price] = $this->check($merchant, $this->string($params, 'product_code'), $mobile);

        // 商品分类的号码限制：同一号码同一分类串行处理，防止并发请求同时通过检查
        $categoryId = $quote['category_id'] ?? null;
        $limits = $categoryId === null ? null : $this->categoryLimitService->limits((int) $categoryId);
        $limited = $limits !== null && ($limits['processing'] > 0 || ($limits['days'] > 0 && $limits['orders'] > 0));
        $categoryLockKey = "order:mobile:{$mobile}:cat:{$categoryId}";
        $categoryToken = null;
        if ($limited) {
            $categoryToken = $this->lock->acquire($categoryLockKey, 10);
            if ($categoryToken === null) {
                throw new OpenApiException(OpenApiException::MOBILE_LIMITED, '该号码有正在处理的订单，请稍后再试');
            }
        }
        // 重复充值拦截（时间窗口在系统配置里设，0 表示不拦截）：同一号码同一面值串行处理，防止并发请求同时通过检查
        $window = $this->orderSettingService->duplicateWindowMinutes();
        $lockKey = "order:mobile:{$mobile}:{$quote['face_value']}";
        $token = null;
        if ($window > 0) {
            $token = $this->lock->acquire($lockKey, 10);
            if ($token === null) {
                if ($categoryToken !== null) {
                    $this->lock->release($categoryLockKey, $categoryToken);
                }
                throw new OpenApiException(OpenApiException::DUPLICATE_RECHARGE, '该号码有正在处理的同面值订单');
            }
        }
        try {
            if ($limited) {
                $this->checkMobileLimit($mobile, (int) $categoryId, $limits);
            }
            if ($window > 0 && $this->orderDao->hasRecent($mobile, $quote['face_value'], self::since($window), self::IN_PROGRESS)) {
                throw new OpenApiException(OpenApiException::DUPLICATE_RECHARGE, self::duplicateMessage($window));
            }

            $order = null;
            for ($try = 1; $order === null; ++$try) {
                try {
                    $order = $this->createAndCharge($merchant, $merchantOrderNo, $quote, $mobile, $operator, $province, $price, $notifyUrl);
                } catch (QueryException $e) {
                    // 平台订单号撞了（同一秒随机数相同）：换一个号重试
                    if ($try < 3 && str_contains($e->getMessage(), 'orders_order_no_unique')) {
                        continue;
                    }
                    throw $e;
                }
            }
        } catch (InsufficientBalanceException $e) {
            throw new OpenApiException(OpenApiException::INSUFFICIENT_BALANCE, $e->getMessage());
        } catch (QueryException $e) {
            // 同一个商户订单号并发提交，唯一索引冲突：返回先建成的那一单
            $existing = $this->orderDao->findByMerchantOrderNo($merchant->id, $merchantOrderNo);
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        } finally {
            if ($token !== null) {
                $this->lock->release($lockKey, $token);
            }
            if ($categoryToken !== null) {
                $this->lock->release($categoryLockKey, $categoryToken);
            }
        }

        if ($order->operator !== $segmentOperator) {
            // 号段和商品的运营商不一致，按商品下单：可能是携号转网，也可能是商户下错了，记下来方便排查
            $this->loggerFactory->get('order')->info('号段运营商和商品不一致，按商品的运营商下单', [
                'order_no' => $order->order_no,
                'segment_operator' => $segmentOperator,
                'operator' => $order->operator,
            ]);
        }
        $this->dispatcher->submit($order->id);

        return $order;
    }

    /**
     * 下单前的检查（不含重复充值和余额）：手机号、商品、黑名单、号段、价格、可用通道。
     * 开放接口下单和商户后台批量下单的预览共用，不通过时抛 OpenApiException。
     * operator 是下单用的运营商，可能和号段识别的 segment_operator 不同（见 orderOperator()）。
     *
     * @return array{quote: array<string, mixed>, operator: string, segment_operator: string, province: string, price: string}
     */
    public function check(Merchant $merchant, string $productCode, string $mobile): array
    {
        if (! $this->mobileSegmentService->isValidMobile($mobile)) {
            throw new OpenApiException(OpenApiException::INVALID_PARAMS, 'mobile 必须是 11 位手机号');
        }
        $quote = preg_match('/^[A-Za-z0-9_-]{1,32}$/', $productCode) === 1 ? $this->priceService->quote($merchant->id, $productCode) : null;
        if ($quote === null || ! $quote['product_active'] || ! $quote['opened_active']) {
            throw new OpenApiException(OpenApiException::PRODUCT_NOT_AVAILABLE, '商品不存在、已下架或没有为你开通');
        }
        if ($this->blacklistService->isBlocked($mobile)) {
            throw new OpenApiException(OpenApiException::BLACKLISTED, '该号码暂不支持充值');
        }
        $segment = $this->mobileSegmentService->identify($mobile);
        if ($segment === null || $segment->is_virtual) {
            throw new OpenApiException(OpenApiException::UNSUPPORTED_MOBILE, $segment === null ? '无法识别该号码的运营商' : '暂不支持虚拟运营商号码');
        }
        $operator = self::orderOperator($segment->operator, $quote['prices']);
        if ($operator === null) {
            throw new OpenApiException(OpenApiException::PRICE_NOT_SET, '该商品暂不支持这个号码的运营商');
        }
        $productId = $quote['product_id'];
        if (($this->productRouteService->candidates([$productId], $operator, $segment->province)[$productId] ?? []) === []) {
            throw new OpenApiException(OpenApiException::NO_CHANNEL, '该号码所在地区暂时无法充值');
        }

        return [
            'quote' => $quote,
            'operator' => $operator,
            'segment_operator' => $segment->operator,
            'province' => $segment->province,
            'price' => (string) $quote['prices'][$operator],
        ];
    }

    /**
     * 重复充值检查的批量版（商户后台批量下单预览用）：这些号码里，同一面值在防重窗口内有进行中订单的那些。关掉拦截时为空。
     *
     * @param list<string> $mobiles
     * @return array<string, string> 手机号 => 原因
     */
    public function duplicateMobiles(array $mobiles, int $faceValue): array
    {
        $window = $this->orderSettingService->duplicateWindowMinutes();
        if ($window <= 0) {
            return [];
        }
        $found = $this->orderDao->recentMobiles($mobiles, $faceValue, self::since($window), self::IN_PROGRESS);

        return array_fill_keys($found, self::duplicateMessage($window));
    }

    /**
     * 给商户看的订单信息（开放接口返回、结果通知用同一份）。等待中、异常都显示为 processing；
     * 人工处理单在手动回调之前也显示为 processing（结果由人工决定什么时候给商户）。
     *
     * @return array<string, mixed>
     */
    public function present(Order $order): array
    {
        return [
            'order_no' => $order->order_no,
            'merchant_order_no' => $order->merchant_order_no,
            'product_code' => $order->product_code,
            'mobile' => $order->mobile,
            'operator' => $order->operator,
            'face_value' => $order->face_value,
            'amount' => $order->sale_price,
            'status' => $order->resultVisibleToMerchant() ? $order->status : 'processing',
            'fail_reason' => $order->resultVisibleToMerchant() && $order->status === Order::STATUS_FAILED ? $order->fail_reason : null,
            'created_at' => $order->created_at?->toDateTimeString(),
            'finished_at' => $order->resultVisibleToMerchant() ? $order->finished_at?->toDateTimeString() : null,
        ];
    }

    /**
     * 按哪个运营商下单（docs/project.md 2.3）：商品有号段运营商的价格就按号段；没有、而商品只设了一个运营商的价格，
     * 就按商品的运营商（号段库认不出携号转网，以商户选的商品为准，号码实际不是这个运营商会充值失败退款）；都不满足返回 null。
     *
     * @param array<string, mixed> $prices 运营商 => 价格
     */
    private static function orderOperator(string $segmentOperator, array $prices): ?string
    {
        if (isset($prices[$segmentOperator])) {
            return $segmentOperator;
        }

        return count($prices) === 1 ? (string) array_key_first($prices) : null;
    }

    /**
     * 商品分类的号码限制（docs/project.md 4.4），所有商户合计：
     * - 同时充值中（没出结果的）的单数不超过 processing；
     * - 周期（自然日，1 表示当天 0 点起）内下的单（所有状态都算）不超过 orders。
     *
     * @param array{days: int, orders: int, processing: int} $limits
     */
    private function checkMobileLimit(string $mobile, int $categoryId, array $limits): void
    {
        if ($limits['processing'] > 0) {
            $count = $this->orderDao->countForMobileInCategory($mobile, $categoryId, null, self::IN_PROGRESS);
            if ($count >= $limits['processing']) {
                throw new OpenApiException(OpenApiException::MOBILE_LIMITED, "该号码已有 {$count} 笔订单在充值中，请等出结果后再充");
            }
        }
        if ($limits['days'] > 0 && $limits['orders'] > 0) {
            $since = date('Y-m-d 00:00:00', strtotime('-' . ($limits['days'] - 1) . ' days'));
            $count = $this->orderDao->countForMobileInCategory($mobile, $categoryId, $since, null);
            if ($count >= $limits['orders']) {
                $period = $limits['days'] === 1 ? '今天' : "近 {$limits['days']} 天";
                throw new OpenApiException(OpenApiException::MOBILE_LIMITED, "该号码{$period}已充值 {$count} 单，达到上限");
            }
        }
    }

    /**
     * 建单和扣款在同一个事务里：余额不够整单回滚。
     *
     * @param array<string, mixed> $quote MerchantPriceService::quote()
     */
    private function createAndCharge(Merchant $merchant, string $merchantOrderNo, array $quote, string $mobile, string $operator, string $province, string $price, string $notifyUrl): Order
    {
        return Db::transaction(function () use ($merchant, $merchantOrderNo, $quote, $mobile, $operator, $province, $price, $notifyUrl) {
            $order = $this->orderDao->create([
                'order_no' => $this->orderNoGenerator->next(),
                'merchant_id' => $merchant->id,
                'merchant_order_no' => $merchantOrderNo,
                'product_id' => $quote['product_id'],
                'product_code' => $quote['product_code'],
                'product_name' => $quote['product_name'],
                'mobile' => $mobile,
                'operator' => $operator,
                'province' => $province,
                'face_value' => $quote['face_value'],
                'sale_price' => $price,
                'status' => Order::STATUS_PENDING,
                'notify_url' => $notifyUrl === '' ? null : $notifyUrl,
                'notify_status' => Order::NOTIFY_NONE,
            ]);
            $this->balanceService->change($merchant->id, MerchantBalanceLog::TYPE_ORDER_PAY, bcmul($price, '-1', 2), $order->id, '下单扣款');

            return $order;
        });
    }

    private static function since(int $minutes): string
    {
        return date('Y-m-d H:i:s', time() - $minutes * 60);
    }

    private static function duplicateMessage(int $minutes): string
    {
        return "该号码 {$minutes} 分钟内有正在处理的同面值订单";
    }

    /**
     * @param array<string, mixed> $params
     */
    private function string(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }
}
