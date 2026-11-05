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
 * 商户：
 * - 余额提醒：余额跌破阈值时给绑定了手机的账号发一次短信，然后暂停（balance_alert_armed = 0），余额回到阈值以上再重新开启；
 *   每天最多发 MerchantBalanceAlertService::DAILY_LIMIT 次（balance_alert_date + balance_alert_count 记当天发了几次）；
 * - 累计消费：consumed_total 是 consumed_until 之前（不含）下单的成功订单售价合计，每天定时结转（MerchantConsumptionCrontab），
 *   列表显示时再加上 consumed_until 之后的实时数。这里先把今天之前的回填掉。
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->decimal('balance_alert_threshold', 14, 2)->nullable()->after('credit_limit')->comment('余额低于这个数发短信提醒充值，空表示不提醒');
            $table->boolean('balance_alert_armed')->default(true)->after('balance_alert_threshold')->comment('跌破阈值时是否要提醒：提醒后置 0，余额回到阈值以上再置 1');
            $table->date('balance_alert_date')->nullable()->after('balance_alert_armed')->comment('最近一次发余额提醒的日期');
            $table->unsignedTinyInteger('balance_alert_count')->default(0)->after('balance_alert_date')->comment('balance_alert_date 当天发了几次提醒');
            $table->decimal('consumed_total', 16, 2)->default(0)->after('balance_alert_count')->comment('consumed_until 之前下单的成功订单售价合计');
            $table->date('consumed_until')->nullable()->after('consumed_total')->comment('累计消费结转到这天（不含），空表示还没结转过');
        });
        $today = date('Y-m-d');
        Db::update(
            "UPDATE merchants m SET consumed_total = (SELECT COALESCE(SUM(o.sale_price), 0) FROM orders o WHERE o.merchant_id = m.id AND o.status = 'success' AND o.created_at < ?), consumed_until = ?",
            [$today . ' 00:00:00', $today]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['balance_alert_threshold', 'balance_alert_armed', 'balance_alert_date', 'balance_alert_count', 'consumed_total', 'consumed_until']);
        });
    }
};
