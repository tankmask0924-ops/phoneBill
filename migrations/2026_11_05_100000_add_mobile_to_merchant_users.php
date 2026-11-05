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
 * 商户后台账号绑定手机：绑定后登录要再输短信验证码，余额提醒短信也发到这里。
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('merchant_users', function (Blueprint $table) {
            $table->string('mobile', 11)->nullable()->after('real_name')->comment('绑定的手机号，绑定后登录要短信验证码');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_users', function (Blueprint $table) {
            $table->dropColumn('mobile');
        });
    }
};
