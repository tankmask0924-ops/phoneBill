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
        Schema::create('merchant_balance_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('merchant_id');
            $table->string('type', 16)->comment('recharge 加款 / deduct 扣款 / order_pay 下单扣款 / order_refund 失败退款');
            $table->decimal('amount', 14, 2)->comment('变动金额，加为正、减为负');
            $table->decimal('balance_after', 14, 2)->comment('变动后余额');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('remark', 255)->nullable();
            $table->unsignedBigInteger('admin_user_id')->nullable()->comment('人工加减款的操作人');
            $table->dateTime('created_at');

            $table->index(['merchant_id', 'id']);
            $table->index('order_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_balance_logs');
    }
};
