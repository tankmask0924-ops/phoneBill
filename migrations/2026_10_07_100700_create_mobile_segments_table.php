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
        Schema::create('mobile_segments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('segment', 7)->unique()->comment('手机号前 7 位');
            $table->string('operator', 8);
            $table->string('province', 16)->comment('省份简称');
            $table->string('city', 32)->nullable();
            $table->boolean('is_virtual')->default(false)->comment('是否虚拟运营商号段');
            $table->datetimes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mobile_segments');
    }
};
