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
 * 管理员手机号：新建 / 编辑管理员时必填；平台启用了短信时，登录要再输发到这个手机的验证码。
 * 已有的管理员先为空，在「管理员账号」里编辑补上，或用 admin:set-mobile 设置。
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->string('mobile', 11)->nullable()->after('real_name')->comment('手机号，平台启用短信时登录验证码发到这里');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn('mobile');
        });
    }
};
