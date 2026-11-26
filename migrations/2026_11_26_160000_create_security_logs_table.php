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
        Schema::create('security_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('portal', 16)->comment('哪一端：admin 管理后台 / merchant 商户后台');
            $table->string('event', 32)->comment('事件：login_success、login_failed、change_password、reset_secret…');
            $table->unsignedBigInteger('user_id')->nullable()->comment('账号 id，用户名不存在时为空');
            $table->string('username', 64)->comment('登录时填的用户名，原样记录（截断到 64 字）');
            $table->unsignedBigInteger('merchant_id')->nullable()->comment('商户后台账号所属商户');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('detail')->nullable()->comment('补充信息，如登录失败原因 reason');
            $table->dateTime('created_at');

            $table->index('created_at');
            $table->index('ip');
            $table->index(['portal', 'username']);
            $table->index('merchant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_logs');
    }
};
