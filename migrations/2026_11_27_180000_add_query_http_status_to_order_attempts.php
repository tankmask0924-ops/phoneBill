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
 * 最后一次查单时接口通不通：上游返回的 HTTP 状态码，0 = 没拿到响应（连不上、超时），空 = 没记录（加这列之前的查单）。
 * 加在表尾，不用 after，大表上 MySQL 8 可以直接加列不重建表。
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('query_http_status')->nullable()->comment('最后一次查单的 HTTP 状态码，0 = 没拿到响应');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_attempts', function (Blueprint $table) {
            $table->dropColumn('query_http_status');
        });
    }
};
