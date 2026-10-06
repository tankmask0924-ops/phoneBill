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
use App\Service\Product\ProductRouteService;
use App\Service\Risk\BlacklistService;
use App\Support\RedisLock;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Exception\QueryException;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;

/**
 * 商户下单（docs/project.md 3.1）：校验 → 识别号码 → 定价 → 选路预检 → 扣款建单 → 推进队列。
 *
 * 任何一步不通过都直接拒绝、不建单、不扣款。同一个商户订单号重复提交返回原订单。
 */
class OrderService extends AbstractService
{
    /** 还没出结果的状态，重复充值拦截看这些 */
    public const IN_PROGRESS = [Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL];

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
    protected ConfigInterface $config;

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
        if (! $this->mobileSegmentService->isValidMobile($mobile)) {
            throw new OpenApiException(OpenApiException::INVALID_PARAMS, 'mobile 必须是 11 位手机号');
        }
        $notifyUrl = $this->string($params, 'notify_url');
        if ($notifyUrl !== '' && (mb_strlen($notifyUrl) > 500 || filter_var($notifyUrl, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#i', $notifyUrl))) {
            throw new OpenApiException(OpenApiException::INVALID_PARAMS, 'notify_url 必须是 http:// 或 https:// 开头的网址');
        }

        $productCode = $this->string($params, 'product_code');
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
        $price = $quote['prices'][$segment->operator] ?? null;
        if ($price === null) {
            throw new OpenApiException(OpenApiException::PRICE_NOT_SET, '该商品暂不支持这个号码的运营商');
        }
        $productId = $quote['product_id'];
        $candidates = $this->productRouteService->candidates([$productId], $segment->operator, $segment->province)[$productId] ?? [];
        if ($candidates === []) {
            throw new OpenApiException(OpenApiException::NO_CHANNEL, '该号码所在地区暂时无法充值');
        }

        // 同一号码同一面值串行处理，防止并发请求同时通过「重复充值」检查
        $lockKey = "order:mobile:{$mobile}:{$quote['face_value']}";
        $token = $this->lock->acquire($lockKey, 10);
        if ($token === null) {
            throw new OpenApiException(OpenApiException::DUPLICATE_RECHARGE, '该号码有正在处理的同面值订单');
        }
        try {
            $windowMinutes = (int) $this->config->get('order.duplicate_window_minutes', 10);
            $since = date('Y-m-d H:i:s', time() - $windowMinutes * 60);
            if ($this->orderDao->hasRecent($mobile, $quote['face_value'], $since, self::IN_PROGRESS)) {
                throw new OpenApiException(OpenApiException::DUPLICATE_RECHARGE, "该号码 {$windowMinutes} 分钟内有正在处理的同面值订单");
            }

            $order = null;
            for ($try = 1; $order === null; ++$try) {
                try {
                    $order = $this->createAndCharge($merchant, $merchantOrderNo, $quote, $mobile, $segment->operator, $segment->province, $price, $notifyUrl);
                } catch (QueryException $e) {
                    // 平台订单号撞了（同一秒随机数相同）：换一个号重试
                    if ($try < 3 && str_contains($e->getMessage(), 'orders_order_no_unique')) {
                        continue;
                    }
                    throw $e;
                }
            }
        } catch (InsufficientBalanceException $e) {
            throw new OpenApiException(OpenApiException::INSUFFICIENT_BALANCE, "余额不足：当前 {$e->balance} 元，需要 {$e->required} 元");
        } catch (QueryException $e) {
            // 同一个商户订单号并发提交，唯一索引冲突：返回先建成的那一单
            $existing = $this->orderDao->findByMerchantOrderNo($merchant->id, $merchantOrderNo);
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        } finally {
            $this->lock->release($lockKey, $token);
        }

        $this->dispatcher->submit($order->id);

        return $order;
    }

    /**
     * 给商户看的订单信息（开放接口返回、结果通知用同一份）。等待中、异常都显示为 processing。
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
            'status' => $order->isFinal() ? $order->status : 'processing',
            'fail_reason' => $order->status === Order::STATUS_FAILED ? $order->fail_reason : null,
            'created_at' => $order->created_at?->toDateTimeString(),
            'finished_at' => $order->isFinal() ? $order->finished_at?->toDateTimeString() : null,
        ];
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

    /**
     * @param array<string, mixed> $params
     */
    private function string(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }
}
