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
        Schema::create('merchants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 64)->unique();
            $table->string('contact', 32)->nullable()->comment('联系人');
            $table->string('phone', 32)->nullable()->comment('联系电话');
            $table->char('app_key', 32)->unique()->comment('开放接口 AppKey');
            $table->text('app_secret')->comment('开放接口签名密钥，加密存储');
            $table->decimal('balance', 14, 2)->default(0)->comment('预存余额（元）');
            $table->string('notify_url', 500)->nullable()->comment('订单结果通知地址');
            $table->string('ip_whitelist', 1000)->nullable()->comment('允许调用开放接口的 IP / CIDR，逗号分隔，空表示不限制');
            $table->string('status', 16)->default('active')->comment('active 启用 / disabled 停用');
            $table->string('remark', 255)->nullable();
            $table->datetimes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
