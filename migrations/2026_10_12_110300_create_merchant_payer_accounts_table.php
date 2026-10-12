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
        Schema::create('merchant_payer_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('merchant_id');
            $table->string('type', 16)->comment('alipay 支付宝 / bank 对公');
            $table->string('account_name', 64)->comment('户名 / 支付宝实名');
            $table->string('account_no', 64)->comment('银行账号 / 支付宝账号');
            $table->string('bank_name', 64)->nullable()->comment('开户行，对公必填');
            $table->string('status', 16)->default('pending')->comment('pending 待审核 / approved 已通过 / rejected 已驳回；商户修改后回到待审核');
            $table->string('reject_reason', 255)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable()->comment('审核的管理员');
            $table->dateTime('reviewed_at')->nullable();
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
        Schema::dropIfExists('merchant_payer_accounts');
    }
};
