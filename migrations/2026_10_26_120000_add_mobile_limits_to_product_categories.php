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
 * 商品分类的号码限制（0 都表示不限）：同一号码在这个分类下，周期（自然日）内最多充几单（所有商户合计、所有状态都算），
 * 同时最多几单在充值中。
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('mobile_limit_days')->default(0)->after('sort')->comment('号码限制周期（自然日），1 表示当天，0 不限');
            $table->unsignedInteger('mobile_limit_orders')->default(0)->after('mobile_limit_days')->comment('周期内同一号码最多几单（所有商户合计、所有状态），0 不限');
            $table->unsignedInteger('mobile_limit_processing')->default(0)->after('mobile_limit_orders')->comment('同一号码同时最多几单在充值中，0 不限');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn(['mobile_limit_days', 'mobile_limit_orders', 'mobile_limit_processing']);
        });
    }
};
