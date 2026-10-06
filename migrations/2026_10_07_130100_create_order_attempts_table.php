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
        Schema::create('order_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->string('attempt_no', 40)->unique()->comment('传给供应商的订单号：平台订单号 + 两位序号');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('supplier_product_id');
            $table->decimal('cost_price', 12, 2);
            $table->string('supplier_order_no', 64)->nullable()->comment('供应商返回的订单号');
            $table->string('status', 16)->comment('processing / success / failed');
            $table->string('message', 255)->nullable()->comment('供应商返回的说明，失败原因');
            $table->text('request')->nullable();
            $table->text('response')->nullable();
            $table->dateTime('submitted_at');
            $table->dateTime('last_queried_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->datetimes();

            $table->index('order_id');
            $table->index(['status', 'submitted_at']);
            $table->index(['supplier_id', 'supplier_order_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_attempts');
    }
};
