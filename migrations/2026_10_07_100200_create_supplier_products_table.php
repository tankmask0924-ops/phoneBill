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
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('name', 64);
            $table->unsignedInteger('face_value')->comment('面值（元）');
            $table->decimal('cost_price', 12, 2)->comment('成本价（元）');
            $table->string('external_code', 64)->comment('供应商侧商品编码');
            $table->string('status', 16)->default('active')->comment('active 上架 / disabled 下架');
            $table->string('remark', 255)->nullable();
            $table->datetimes();

            $table->index('supplier_id');
            $table->index('face_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
    }
};
