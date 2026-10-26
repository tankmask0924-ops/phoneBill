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

/*
 * 支付宝转账加款从「生成带随机分的支付单 + 定时核对」改成「固定收款码 + 商户填支付宝订单号查询到账」：
 * - 收款账户加固定收款码图片，去掉二维码有效期；
 * - 支付单去掉应付金额占用、二维码到期、核对截止，支付宝订单号改成唯一（一笔转账只能提交一次），加最近一次查询的时间和结果；
 * - 以前没到账的待支付单不会再自动核对，改成已过期，真到账了由后台查找到账记录确认入账。
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('alipay_accounts', function (Blueprint $table) {
            $table->string('qr_code', 255)->nullable()->after('account_name')->comment('固定收款码图片（COS 对象 key）');
            $table->dropColumn('expire_minutes');
        });

        Db::table('merchant_online_payments')->where('status', 'pending')->update(['status' => 'expired']);

        Schema::table('merchant_online_payments', function (Blueprint $table) {
            $table->dropUnique(['alipay_account_id', 'active_amount']);
            $table->dropIndex(['status', 'checking_until']);
        });
        Schema::table('merchant_online_payments', function (Blueprint $table) {
            $table->dropColumn(['active_amount', 'expires_at', 'checking_until']);
            $table->unique('alipay_order_no');
            $table->dateTime('checked_at')->nullable()->after('status')->comment('最近一次查询支付宝的时间');
            $table->string('check_message', 255)->nullable()->after('checked_at')->comment('最近一次查询的结果，没到账时告诉商户原因');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_online_payments', function (Blueprint $table) {
            $table->dropUnique(['alipay_order_no']);
            $table->dropColumn(['checked_at', 'check_message']);
            $table->decimal('active_amount', 14, 2)->nullable()->after('pay_amount');
            $table->dateTime('expires_at')->nullable()->after('status');
            $table->dateTime('checking_until')->nullable()->after('expires_at');
            $table->unique(['alipay_account_id', 'active_amount']);
            $table->index(['status', 'checking_until']);
        });
        Schema::table('alipay_accounts', function (Blueprint $table) {
            $table->dropColumn('qr_code');
            $table->unsignedSmallInteger('expire_minutes')->default(15)->after('account_name')->comment('二维码有效期（分钟）');
        });
    }
};
