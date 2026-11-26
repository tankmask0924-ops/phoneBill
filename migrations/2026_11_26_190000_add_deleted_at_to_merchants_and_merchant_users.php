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
     * 商户、商户后台账号改为软删除：删掉后列表里不再出现、不能登录和下单，历史订单、流水还能查到名字。
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->softDeletes()->comment('删除时间，删除后商户名称仍然占用');
        });
        Schema::table('merchant_users', function (Blueprint $table) {
            $table->softDeletes()->comment('删除时间，删除后登录账号仍然占用');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('merchant_users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
