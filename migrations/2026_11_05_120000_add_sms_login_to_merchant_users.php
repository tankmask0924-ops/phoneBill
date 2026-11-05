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
use Hyperf\DbConnection\Db;

/*
 * 商户后台账号自己选是否开启登录短信验证（要先绑定手机）。
 * 之前绑定了手机就一定要验证，已经绑定的账号保持开启，行为不变。
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('merchant_users', function (Blueprint $table) {
            $table->boolean('sms_login_enabled')->default(false)->after('mobile')->comment('登录时是否要短信验证码（要先绑定手机）');
        });
        Db::table('merchant_users')->whereNotNull('mobile')->where('mobile', '<>', '')->update(['sms_login_enabled' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_users', function (Blueprint $table) {
            $table->dropColumn('sms_login_enabled');
        });
    }
};
