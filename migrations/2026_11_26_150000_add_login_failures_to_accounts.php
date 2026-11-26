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
 * 管理后台、商户后台账号连续输错密码的次数：连续错 5 次自动封禁（status 改成 disabled），
 * 登录成功、后台重新启用、admin:unlock 时清零。
 */
return new class extends Migration {
    private const TABLES = ['admin_users', 'merchant_users'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedInteger('login_failures')->default(0)->after('status')->comment('连续输错密码次数，满 5 次自动封禁');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('login_failures');
            });
        }
    }
};
