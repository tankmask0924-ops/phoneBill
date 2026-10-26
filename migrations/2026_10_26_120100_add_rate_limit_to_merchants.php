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

/*
 * 商户下单接口限流：每 rate_limit_seconds 秒最多 rate_limit_orders 单，任一为 0 表示不限。
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->unsignedInteger('rate_limit_seconds')->default(0)->after('credit_limit')->comment('下单限流的时间窗口（秒），0 不限');
            $table->unsignedInteger('rate_limit_orders')->default(0)->after('rate_limit_seconds')->comment('时间窗口内最多下几单，0 不限');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['rate_limit_seconds', 'rate_limit_orders']);
        });
    }
};
