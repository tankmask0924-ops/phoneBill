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
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('manual_only')->default(false)->after('notify_status')->comment('人工处理单：提交时接口异常，上游结果只记录，状态和回调全部人工');
            $table->dateTime('manual_notified_at')->nullable()->after('manual_only')->comment('最近一次手动回调时间；之后上游结果不再推给商户');
            $table->unsignedInteger('notify_round')->default(0)->after('manual_notified_at')->comment('通知轮次，每次手动回调加 1，旧轮次排队中的通知作废');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['manual_only', 'manual_notified_at', 'notify_round']);
        });
    }
};
