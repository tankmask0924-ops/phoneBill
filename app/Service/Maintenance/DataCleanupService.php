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

namespace App\Service\Maintenance;

use App\Service\AbstractService;
use App\Service\Merchant\MerchantPriceService;
use App\Service\Product\ProductRouteService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;

/**
 * 清掉订单和商品数据（上线前清测试数据用），由 data:clean-orders-products 命令调用。
 *
 * - 订单：订单、供应商提交记录、通知商户记录、商品每日统计；
 * - 商品：平台商品、绑定、默认售价、商品分类、商户开通的商品和价格、供应商商品及其运营商 / 省份；
 * - 资金：所有商户余额归零，所有资金流水（含人工加款、加款申请入账）全删，已入账的加款申请去掉流水关联；
 * - 保留：供应商、商户及其后台账号、管理员和权限、号段、黑名单、通道维护、收款配置、付款账号、加款申请、系统配置、操作日志。
 *
 * 全部在一个事务里用 DELETE 删，出错整体回滚；删完清掉选路、商户价格缓存。
 */
class DataCleanupService extends AbstractService
{
    /** 按删除顺序（先删引用别人的） */
    public const TABLES = [
        'order_notify_logs' => '通知商户记录',
        'order_attempts' => '供应商提交记录',
        'orders' => '订单',
        'product_daily_stats' => '商品每日统计',
        'merchant_balance_logs' => '资金流水（全部）',
        'merchant_product_prices' => '商户商品价格',
        'merchant_products' => '商户开通的商品',
        'product_routes' => '平台商品绑定',
        'product_prices' => '平台商品默认售价',
        'products' => '平台商品',
        'product_categories' => '商品分类',
        'supplier_product_operators' => '供应商商品运营商',
        'supplier_product_provinces' => '供应商商品省份',
        'supplier_products' => '供应商商品',
    ];

    #[Inject]
    protected ProductRouteService $routeService;

    #[Inject]
    protected MerchantPriceService $priceService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 各表现在的行数，以及余额不为 0 的商户数。
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach (array_keys(self::TABLES) as $table) {
            $counts[$table] = Db::table($table)->count();
        }
        $counts['merchants_with_balance'] = Db::table('merchants')->where('balance', '<>', 0)->count();

        return $counts;
    }

    /**
     * 执行清理，返回各表删掉的行数和余额归零的商户数。
     *
     * @return array<string, int>
     */
    public function clean(): array
    {
        $deleted = Db::transaction(function () {
            $deleted = [];
            foreach (array_keys(self::TABLES) as $table) {
                $deleted[$table] = Db::table($table)->delete();
            }
            // 资金流水删光了，已入账的加款申请不再指向流水；申请记录本身保留
            Db::table('merchant_funding_requests')->whereNotNull('balance_log_id')->update(['balance_log_id' => null]);
            $deleted['merchants_with_balance'] = Db::table('merchants')->where('balance', '<>', 0)->update(['balance' => 0]);

            return $deleted;
        });
        $this->routeService->flushConfig();
        $this->priceService->flush();
        $this->loggerFactory->get('maintenance')->warning('已清理订单和商品数据', $deleted);

        return $deleted;
    }
}
