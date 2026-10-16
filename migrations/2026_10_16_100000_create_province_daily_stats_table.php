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

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('province_daily_stats', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('stat_date')->comment('按下单时间归属的日期');
            $table->string('province', 16);
            $table->string('operator', 8);
            $table->unsignedBigInteger('supplier_id')->default(0)->comment('订单最终的供应商：成功的取成交供应商，其余取最后一次提交的；0 = 没提交过');
            $table->unsignedInteger('order_count');
            $table->unsignedInteger('success_count');
            $table->unsignedInteger('failed_count');
            $table->unsignedInteger('unfinished_count')->comment('统计时还没出结果的（待提交、处理中、异常）');
            $table->decimal('success_amount', 14, 2)->default(0)->comment('成功订单售价合计');
            $table->decimal('profit', 14, 2)->default(0)->comment('成功订单利润合计');
            $table->dateTime('computed_at');

            $table->unique(['stat_date', 'province', 'operator', 'supplier_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('province_daily_stats');
    }
};
