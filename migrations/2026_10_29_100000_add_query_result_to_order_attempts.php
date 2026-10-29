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
 * 每次查单（定时和人工）的结果和原始报文记在提交上，订单详情里展示。
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_attempts', function (Blueprint $table) {
            $table->string('query_status', 16)->nullable()->after('last_queried_at')->comment('最后一次查单结果：success / failed / processing');
            $table->string('query_message', 255)->nullable()->after('query_status')->comment('最后一次查单的说明');
            $table->text('query_request')->nullable()->after('query_message')->comment('最后一次查单的请求报文（密钥已打码）');
            $table->text('query_response')->nullable()->after('query_request')->comment('最后一次查单的响应报文');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_attempts', function (Blueprint $table) {
            $table->dropColumn(['query_status', 'query_message', 'query_request', 'query_response']);
        });
    }
};
