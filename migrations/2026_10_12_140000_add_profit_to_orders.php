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
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

return new class extends Migration {
    /**
     * 订单利润 = 扣款 - 成本，订单成功时写入；回填已有的成功订单。
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('profit', 10, 2)->nullable()->after('cost_price')->comment('利润 = 扣款 - 成本，成功时写入，失败（含冲正）为空');
        });
        Db::statement("UPDATE orders SET profit = sale_price - cost_price WHERE status = 'success' AND cost_price IS NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('profit');
        });
    }
};
