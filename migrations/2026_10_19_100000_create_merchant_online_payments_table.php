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
        Schema::create('merchant_online_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('order_no', 32)->unique()->comment('支付单号，转账时填在备注里');
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('merchant_user_id')->nullable()->comment('发起的商户后台账号');
            $table->decimal('amount', 14, 2)->comment('商户输入的金额');
            $table->decimal('pay_amount', 14, 2)->comment('实际要付的金额（加了随机分），到账后按这个入账');
            // 占用中的应付金额：未结束的支付单之间唯一，靠它认到账；结束并过了核对期后清空，金额才能给别的单用
            $table->decimal('active_amount', 14, 2)->nullable()->unique();
            $table->string('receive_account', 64)->comment('收款支付宝账号（快照）');
            $table->string('receive_name', 64)->comment('收款人姓名（快照）');
            $table->string('status', 16)->default('pending')->comment('pending 待支付 / paid 已到账 / expired 已过期 / cancelled 已取消');
            $table->dateTime('expires_at')->comment('二维码到期时间，之后还会再核对一段时间');
            $table->dateTime('checking_until')->comment('自动核对截止时间，过了还没到账就过期，并在这之后释放金额');
            $table->dateTime('paid_at')->nullable()->comment('入账时间');
            $table->string('confirm_type', 16)->nullable()->comment('auto 自动核对 / manual 后台确认');
            $table->string('account_log_id', 64)->nullable()->unique()->comment('匹配到的支付宝账务流水号，一条流水只能入账一次');
            $table->string('alipay_order_no', 64)->nullable();
            $table->string('payer_account', 128)->nullable()->comment('付款方账户（支付宝返回的对方账户）');
            $table->string('trans_memo', 255)->nullable()->comment('支付宝账务备注');
            $table->dateTime('trans_at')->nullable()->comment('支付宝入账时间');
            $table->string('remark', 255)->nullable()->comment('后台确认入账时的备注');
            $table->unsignedBigInteger('confirmed_by')->nullable()->comment('后台确认入账的管理员');
            $table->unsignedBigInteger('balance_log_id')->nullable()->comment('入账的资金流水');
            $table->datetimes();
            $table->index(['merchant_id', 'id']);
            $table->index(['status', 'id']);
            $table->index(['status', 'checking_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_online_payments');
    }
};
