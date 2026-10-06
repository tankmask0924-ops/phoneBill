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
 * 每天几十万单时，按商户 / 供应商 + 时间查订单、按商户 + 时间查流水要走索引。
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['merchant_id', 'created_at']);
            $table->index(['supplier_id', 'created_at']);
        });
        Schema::table('merchant_balance_logs', function (Blueprint $table) {
            $table->index(['merchant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'created_at']);
            $table->dropIndex(['supplier_id', 'created_at']);
        });
        Schema::table('merchant_balance_logs', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'created_at']);
        });
    }
};
