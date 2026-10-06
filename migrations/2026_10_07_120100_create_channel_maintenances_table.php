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
        Schema::create('channel_maintenances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('operator', 8)->nullable()->comment('空表示该供应商所有运营商');
            $table->string('province', 16)->nullable()->comment('空表示该供应商所有省份');
            $table->dateTime('start_at');
            $table->dateTime('end_at')->comment('到这个时间自动恢复');
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('admin_user_id')->nullable()->comment('添加人');
            $table->datetimes();

            $table->index(['supplier_id', 'end_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_maintenances');
    }
};
