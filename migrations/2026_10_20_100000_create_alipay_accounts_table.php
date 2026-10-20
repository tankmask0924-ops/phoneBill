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
use App\Security\Encrypter;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

/*
 * 支付宝转账加款从一份系统配置改成多个收款账户，每个商户指定用哪个。
 * 以前的系统配置（payment.alipay_transfer）转成第一个账户，以前所有商户都能用，所以全部指定到这个账户。
 */
return new class extends Migration {
    private const OLD_SETTING_KEY = 'payment.alipay_transfer';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('alipay_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 64)->comment('账户名称，后台区分用');
            $table->string('app_id', 32)->comment('开放平台应用 AppID');
            $table->text('private_key')->comment('应用私钥（加密存储）');
            $table->text('alipay_public_key')->comment('支付宝公钥');
            $table->string('account', 64)->comment('收款支付宝账号');
            $table->string('account_name', 64)->comment('收款人姓名');
            $table->unsignedSmallInteger('expire_minutes')->default(15)->comment('二维码有效期（分钟）');
            $table->decimal('min_amount', 14, 2)->comment('单笔最低金额');
            $table->decimal('max_amount', 14, 2)->comment('单笔最高金额');
            $table->string('status', 16)->default('active')->comment('active / disabled');
            $table->string('remark', 255)->nullable();
            $table->datetimes();
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->unsignedBigInteger('alipay_account_id')->nullable()->comment('支付宝转账加款用的收款账户，空表示不能用');
        });

        Schema::table('merchant_online_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('alipay_account_id')->nullable()->after('merchant_user_id')->comment('收款的支付宝账户');
            // 应付金额只需在同一个收款账户的未结束单之间唯一（各账户分开查账务明细）
            $table->dropUnique(['active_amount']);
            $table->unique(['alipay_account_id', 'active_amount']);
        });

        $this->migrateOldSetting();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_online_payments', function (Blueprint $table) {
            $table->dropUnique(['alipay_account_id', 'active_amount']);
            $table->unique(['active_amount']);
            $table->dropColumn('alipay_account_id');
        });
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('alipay_account_id');
        });
        Schema::dropIfExists('alipay_accounts');
    }

    private function migrateOldSetting(): void
    {
        $row = Db::table('system_settings')->where('key', self::OLD_SETTING_KEY)->first();
        if ($row === null || $row->value === null) {
            return;
        }
        $encrypter = ApplicationContext::getContainer()->get(Encrypter::class);
        $config = json_decode($encrypter->decrypt($row->value), true);
        if (is_array($config) && ($config['app_id'] ?? '') !== '' && ($config['private_key'] ?? '') !== '') {
            $now = date('Y-m-d H:i:s');
            $id = Db::table('alipay_accounts')->insertGetId([
                'name' => '支付宝收款账户',
                'app_id' => (string) $config['app_id'],
                'private_key' => $encrypter->encrypt((string) $config['private_key']),
                'alipay_public_key' => (string) ($config['alipay_public_key'] ?? ''),
                'account' => (string) ($config['account'] ?? ''),
                'account_name' => (string) ($config['account_name'] ?? ''),
                'expire_minutes' => (int) ($config['expire_minutes'] ?? 15),
                'min_amount' => (string) ($config['min_amount'] ?? '1.00'),
                'max_amount' => (string) ($config['max_amount'] ?? '50000.00'),
                'status' => ($config['enabled'] ?? false) ? 'active' : 'disabled',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::table('merchant_online_payments')->whereNull('alipay_account_id')->update(['alipay_account_id' => $id]);
            Db::table('merchants')->update(['alipay_account_id' => $id]);
        }
        Db::table('system_settings')->where('key', self::OLD_SETTING_KEY)->delete();
    }
};
