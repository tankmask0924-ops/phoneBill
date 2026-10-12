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

return new class extends Migration {
    /**
     * 覆盖省份从供应商挪到供应商商品：建表，并把每个供应商原来的省份复制给它名下的每个供应商商品，选路结果不变。
     * 旧表 supplier_provinces 保留不再使用，后续版本再删。
     */
    public function up(): void
    {
        Schema::create('supplier_product_provinces', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_product_id');
            $table->string('province', 16)->comment('省份简称，* 表示全国');
            $table->unique(['supplier_product_id', 'province'], 'spp_product_province_unique');
            $table->index('province', 'spp_province_index');
        });
        Db::statement('INSERT INTO supplier_product_provinces (supplier_product_id, province)
            SELECT sp.id, p.province FROM supplier_products sp JOIN supplier_provinces p ON p.supplier_id = sp.supplier_id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_product_provinces');
    }
};
