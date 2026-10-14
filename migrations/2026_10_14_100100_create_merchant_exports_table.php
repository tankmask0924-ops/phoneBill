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
     * 商户后台的导出记录：异步生成 CSV 存在服务器本地，保留 7 天。
     */
    public function up(): void
    {
        Schema::create('merchant_exports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('merchant_user_id')->comment('发起导出的商户后台账号');
            $table->string('type', 32)->comment('orders 订单记录 / balance_logs 资金流水');
            $table->json('filters')->comment('导出时的筛选条件');
            $table->string('status', 16)->comment('pending 生成中 / success 已完成 / failed 失败');
            $table->string('file_name', 128)->comment('下载时的文件名');
            $table->string('file_path', 255)->nullable()->comment('相对 runtime/exports 的路径');
            $table->unsignedInteger('row_count')->nullable();
            $table->string('error', 255)->nullable()->comment('失败原因');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_exports');
    }
};
