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
        Schema::create('merchant_funding_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('merchant_user_id')->nullable()->comment('提交的商户后台账号');
            $table->decimal('amount', 14, 2)->comment('申请金额，审核通过按这个入账');
            // 付款账号、收款账户存快照：账号之后被修改、删除、停用都不影响这笔申请
            $table->unsignedBigInteger('payer_account_id')->nullable();
            $table->string('payer_type', 16);
            $table->string('payer_account_name', 64);
            $table->string('payer_account_no', 64);
            $table->string('payer_bank_name', 64)->nullable();
            $table->unsignedBigInteger('receiving_account_id')->nullable();
            $table->string('receiving_type', 16);
            $table->string('receiving_account_name', 64);
            $table->string('receiving_account_no', 64);
            $table->string('receiving_bank_name', 64)->nullable();
            $table->dateTime('paid_at')->comment('打款时间');
            $table->string('remark', 255)->nullable()->comment('打款备注');
            $table->json('vouchers')->comment('凭证图片在对象存储里的 key 列表');
            $table->string('status', 16)->default('pending')->comment('pending 待审核 / approved 已入账 / rejected 已驳回');
            $table->string('review_remark', 255)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->unsignedBigInteger('balance_log_id')->nullable()->comment('入账的资金流水');
            $table->datetimes();
            $table->index(['merchant_id', 'id']);
            $table->index(['status', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_funding_requests');
    }
};
