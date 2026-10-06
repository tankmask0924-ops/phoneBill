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
        Schema::create('product_daily_stats', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('stat_date')->comment('按下单时间归属的日期');
            $table->unsignedBigInteger('product_id');
            $table->string('operator', 8);
            $table->unsignedInteger('order_count')->comment('下单受理的订单数');
            $table->unsignedInteger('success_count');
            $table->unsignedInteger('failed_count');
            $table->unsignedInteger('unfinished_count')->comment('统计时还没出结果的（处理中、异常）');
            $table->decimal('success_rate', 5, 2)->nullable()->comment('成功 / (成功 + 失败) × 100，没有出结果的单时为空');
            $table->unsignedBigInteger('total_duration')->default(0)->comment('成功订单耗时合计（秒），按天汇总多个运营商时加权用');
            $table->unsignedInteger('avg_duration')->nullable()->comment('成功订单平均耗时（秒）：下单受理到成功');
            $table->unsignedInteger('p50_duration')->nullable()->comment('成功订单耗时中位数（秒）');
            $table->unsignedInteger('p90_duration')->nullable()->comment('90% 的成功订单在这个秒数内到账');
            $table->dateTime('computed_at');

            $table->unique(['stat_date', 'product_id', 'operator']);
            $table->index(['product_id', 'stat_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_daily_stats');
    }
};
