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
        Schema::create('suppliers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 64)->unique()->comment('供应商名称');
            $table->string('code', 32)->unique()->comment('供应商编码，用在回调地址里');
            $table->string('driver', 32)->comment('对接驱动编码，见 App\Supplier\SupplierDriverRegistry');
            $table->text('config')->nullable()->comment('接口参数 JSON，整体加密存储');
            $table->string('status', 16)->default('active')->comment('active 启用 / disabled 停用');
            $table->string('remark', 255)->nullable();
            $table->datetimes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
