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

namespace HyperfTest\Cases\Command;

use App\Command\CleanOrdersProductsCommand;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantFundingRequest;
use App\Model\Order;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\Service\Maintenance\DataCleanupService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\DbConnection\Db;
use HyperfTest\Cases\Admin\CreatesCatalog;
use HyperfTest\HttpTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * data:clean-orders-products：只看不删、确认不对就取消；真删时在外层事务里跑完再回滚，不动开发库数据。
 *
 * @internal
 * @coversNothing
 */
class CleanOrdersProductsCommandTest extends HttpTestCase
{
    use CreatesCatalog;

    protected function tearDown(): void
    {
        $this->cleanUpCatalog();
        parent::tearDown();
    }

    public function testDryRunAndWrongConfirmationDeleteNothing()
    {
        [, $order] = $this->seed();
        $before = $this->service()->counts();

        $tester = $this->tester();
        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $this->assertStringContainsString('订单', $tester->getDisplay());
        $this->assertStringContainsString('没有删除', $tester->getDisplay());

        $tester = $this->tester();
        $tester->setInputs(['not_the_database']);
        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('已取消', $tester->getDisplay());

        $this->assertSame($before, $this->service()->counts());
        $this->assertNotNull(Order::find($order->id));
    }

    public function testCleanWipesOrdersProductsAndBalancesButKeepsTheRest()
    {
        [$merchant, $order, $product, $supplierProduct, $supplier] = $this->seed();
        $funding = MerchantFundingRequest::create([
            'merchant_id' => $merchant->id, 'amount' => '100.00', 'payer_type' => 'alipay', 'payer_account_name' => 'x', 'payer_account_no' => 'x',
            'receiving_type' => 'alipay', 'receiving_account_name' => 'y', 'receiving_account_no' => 'y', 'paid_at' => date('Y-m-d H:i:s'),
            'vouchers' => ['k'], 'status' => 'approved', 'balance_log_id' => MerchantBalanceLog::where('merchant_id', $merchant->id)->value('id'),
        ]);

        Db::beginTransaction();
        try {
            $tester = $this->tester();
            $tester->setInputs([(string) ApplicationContext::getContainer()->get(ConfigInterface::class)->get('databases.default.database')]);
            $this->assertSame(0, $tester->execute([]));
            $this->assertStringContainsString('清理完成', $tester->getDisplay());

            foreach (array_keys(DataCleanupService::TABLES) as $table) {
                $this->assertSame(0, Db::table($table)->count(), $table);
            }
            $this->assertSame(0, Db::table('merchants')->where('balance', '<>', 0)->count(), '所有商户余额归零');
            $this->assertNull(Order::find($order->id));
            $this->assertNull(Product::find($product->id));
            $this->assertNull(SupplierProduct::find($supplierProduct->id));
            // 保留的
            $this->assertNotNull(Merchant::find($merchant->id));
            $this->assertNotNull(Supplier::find($supplier->id));
            $this->assertNull(MerchantFundingRequest::find($funding->id)->balance_log_id, '加款申请保留，去掉流水关联');
        } finally {
            Db::rollBack();
            $funding->delete();
        }
        $this->assertNotNull(Order::find($order->id), '回滚后数据都在');
    }

    /**
     * 一个有余额的商户、一笔订单（带扣款流水）、平台商品和供应商商品。
     *
     * @return array{0: Merchant, 1: Order, 2: Product, 3: SupplierProduct, 4: Supplier}
     */
    private function seed(): array
    {
        $supplier = $this->createSupplier();
        $supplierProduct = $this->createSupplierProduct($supplier, ['cmcc']);
        $product = $this->createProduct([$supplierProduct->id => 1]);
        $merchant = $this->createMerchant('100.00');
        $this->openProduct($merchant, $product, ['cmcc' => '99.50']);
        $order = Order::create([
            'order_no' => date('YmdHis') . random_int(100000, 999999), 'merchant_id' => $merchant->id, 'merchant_order_no' => $this->uniq('M'),
            'product_id' => $product->id, 'product_code' => $product->code, 'product_name' => $product->name, 'mobile' => '13800138000',
            'operator' => 'cmcc', 'province' => '广东', 'face_value' => 100, 'sale_price' => '99.50', 'status' => 'processing', 'notify_status' => 'none',
        ]);
        MerchantBalanceLog::create([
            'merchant_id' => $merchant->id, 'type' => 'order_pay', 'amount' => '-99.50', 'balance_after' => '0.50',
            'order_id' => $order->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [$merchant, $order, $product, $supplierProduct, $supplier];
    }

    private function tester(): CommandTester
    {
        return new CommandTester(ApplicationContext::getContainer()->get(CleanOrdersProductsCommand::class));
    }

    private function service(): DataCleanupService
    {
        return ApplicationContext::getContainer()->get(DataCleanupService::class);
    }
}
