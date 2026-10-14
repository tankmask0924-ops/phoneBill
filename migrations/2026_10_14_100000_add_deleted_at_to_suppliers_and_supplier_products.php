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
     * 供应商、供应商商品改为软删除：删掉后列表和下拉里不再出现，历史订单还能查到名字。
     */
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->softDeletes()->comment('删除时间，删除后名称和编码仍然占用');
        });
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->softDeletes()->comment('删除时间');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
