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
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('order_no', 32)->unique()->comment('平台订单号');
            $table->unsignedBigInteger('merchant_id');
            $table->string('merchant_order_no', 64)->comment('商户订单号，商户内唯一');
            $table->unsignedBigInteger('product_id');
            $table->string('product_code', 32);
            $table->string('product_name', 64);
            $table->char('mobile', 11);
            $table->string('operator', 8);
            $table->string('province', 16);
            $table->unsignedInteger('face_value')->comment('面值（元）');
            $table->decimal('sale_price', 12, 2)->comment('扣商户的钱，下单时的价格快照');
            $table->decimal('cost_price', 12, 2)->nullable()->comment('成功那次尝试的成本价');
            $table->unsignedBigInteger('supplier_id')->nullable()->comment('成功的供应商');
            $table->unsignedBigInteger('supplier_product_id')->nullable();
            $table->string('status', 16)->comment('pending / processing / abnormal / success / failed');
            $table->string('fail_reason', 255)->nullable();
            $table->string('notify_url', 500)->nullable()->comment('下单时传的通知地址，空则用商户配置的');
            $table->string('notify_status', 16)->default('none')->comment('none 不用通知 / pending 待通知 / retrying 重试中 / success 已送达 / failed 放弃');
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->datetimes();

            $table->unique(['merchant_id', 'merchant_order_no']);
            $table->index(['mobile', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
