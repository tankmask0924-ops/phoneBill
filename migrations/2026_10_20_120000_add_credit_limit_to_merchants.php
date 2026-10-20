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
        Schema::table('merchants', function (Blueprint $table) {
            // 有授信时余额可以扣到负数，最低到 -credit_limit；0 表示不能负数（和以前一样）
            $table->decimal('credit_limit', 14, 2)->default(0)->after('balance')->comment('授信额度');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('credit_limit');
        });
    }
};
