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

namespace App\Service\Supplier;

use App\Dao\SupplierDao;
use App\Dao\SupplierProductDao;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\Supplier;
use App\Security\Encrypter;
use App\Service\AbstractService;
use App\Supplier\CallbackResult;
use App\Supplier\ProvidesUpstreamProducts;
use App\Supplier\RechargeRequest;
use App\Supplier\RechargeResult;
use App\Supplier\SupplierDriverInterface;
use App\Supplier\SupplierDriverRegistry;
use App\Supplier\UpstreamProduct;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * 调供应商的统一出口：找驱动、解密接口参数、兜住驱动抛出的异常、记日志（渠道 supplier）。
 *
 * 驱动抛异常（网络超时等）一律当处理中，绝不当失败，之后靠回调或查单拿结果。
 */
class SupplierGateway extends AbstractService
{
    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierProductDao $supplierProductDao;

    #[Inject]
    protected SupplierDriverRegistry $driverRegistry;

    #[Inject]
    protected Encrypter $encrypter;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function recharge(Order $order, OrderAttempt $attempt): RechargeResult
    {
        return $this->call('recharge', $order, $attempt);
    }

    public function query(Order $order, OrderAttempt $attempt): RechargeResult
    {
        return $this->call('query', $order, $attempt);
    }

    public function parseCallback(Supplier $supplier, ServerRequestInterface $request): CallbackResult
    {
        $result = $this->driver($supplier)->parseCallback($request, $this->decryptConfig($supplier));
        $this->logger()->info('callback', [
            'supplier' => $supplier->code,
            'verified' => $result->verified,
            'attempt_no' => $result->attemptNo,
            'supplier_order_no' => $result->supplierOrderNo,
            'status' => $result->result?->status->value,
            'body' => mb_substr((string) $request->getBody(), 0, 2000),
        ]);

        return $result;
    }

    /**
     * 驱动是否提供查询上游商品。
     */
    public function supportsUpstreamProducts(Supplier $supplier): bool
    {
        return $this->driverRegistry->find($supplier->driver) instanceof ProvidesUpstreamProducts;
    }

    /**
     * 查上游可售商品，出错直接抛异常（只给后台建商品时用，不影响下单）。
     *
     * @return list<UpstreamProduct>
     */
    public function upstreamProducts(Supplier $supplier): array
    {
        $driver = $this->driver($supplier);
        if (! $driver instanceof ProvidesUpstreamProducts) {
            throw new RuntimeException("供应商 {$supplier->code} 的驱动不支持查询商品");
        }
        $products = $driver->upstreamProducts($this->decryptConfig($supplier));
        $this->logger()->info('upstream_products', ['supplier' => $supplier->code, 'count' => count($products)]);

        return $products;
    }

    /**
     * 解密后的完整接口参数（含密钥），不要返回给前端。
     *
     * @return array<string, string>
     */
    public function decryptConfig(Supplier $supplier): array
    {
        if ($supplier->config === null || $supplier->config === '') {
            return [];
        }
        $config = json_decode($this->encrypter->decrypt($supplier->config), true);

        return is_array($config) ? $config : [];
    }

    private function call(string $method, Order $order, OrderAttempt $attempt): RechargeResult
    {
        $supplier = $this->supplierDao->find($attempt->supplier_id);
        $supplierProduct = $this->supplierProductDao->find($attempt->supplier_product_id);
        if ($supplier === null || $supplierProduct === null) {
            throw new RuntimeException("尝试 {$attempt->attempt_no} 的供应商或供应商商品不存在");
        }
        $request = new RechargeRequest(
            $attempt->attempt_no,
            $order->mobile,
            $order->face_value,
            $order->operator,
            $order->province,
            $supplierProduct->external_code,
            $this->decryptConfig($supplier),
            $attempt->supplier_order_no,
        );

        try {
            $result = $this->driver($supplier)->{$method}($request);
        } catch (Throwable $e) {
            $result = RechargeResult::processing(null, mb_substr('调用异常，按处理中等待结果：' . $e->getMessage(), 0, 255));
        }
        $this->logger()->info($method, [
            'supplier' => $supplier->code,
            'attempt_no' => $attempt->attempt_no,
            'status' => $result->status->value,
            'supplier_order_no' => $result->supplierOrderNo,
            'message' => $result->message,
        ]);

        return $result;
    }

    private function driver(Supplier $supplier): SupplierDriverInterface
    {
        $driver = $this->driverRegistry->find($supplier->driver);
        if ($driver === null) {
            throw new RuntimeException("供应商 {$supplier->code} 的驱动 {$supplier->driver} 没有登记");
        }

        return $driver;
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('supplier');
    }
}
