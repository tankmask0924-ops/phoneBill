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

namespace HyperfTest\Cases\Merchant;

use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantExport;
use App\Model\MerchantUser;
use App\Model\Order;
use App\Service\MerchantPortal\ExportDispatcher;
use App\Service\MerchantPortal\MerchantExportService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\Cases\Admin\CreatesAdmins;
use HyperfTest\Cases\Admin\CreatesCatalog;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 商户后台导出：按列表的筛选条件异步生成 CSV、导出列表、下载、只能下载自己的、过期清理。
 * 队列换成假的，测试里直接调 generate()。
 *
 * @internal
 * @coversNothing
 */
class MerchantExportTest extends HttpTestCase
{
    use CreatesAdmins;
    use CreatesCatalog;

    private const PASSWORD = 'correct-password';

    /** @var list<int> 推过的导出 id */
    private array $dispatched = [];

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $dispatched = &$this->dispatched;
        ApplicationContext::getContainer()->set(ExportDispatcher::class, new class($dispatched) extends ExportDispatcher {
            public function __construct(private array &$ids)
            {
            }

            public function generate(int $exportId): void
            {
                $this->ids[] = $exportId;
            }
        });
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        foreach (MerchantExport::whereIn('merchant_id', $this->merchantIds)->get() as $export) {
            if ($export->file_path !== null && is_file(MerchantExportService::baseDir() . '/' . $export->file_path)) {
                unlink(MerchantExportService::baseDir() . '/' . $export->file_path);
            }
            $export->delete();
        }
        $this->cleanUpCatalog();
        parent::tearDown();
    }

    public function testExportOrdersWithFiltersAndDownload()
    {
        $merchant = $this->createMerchant('500.00');
        $other = $this->createMerchant('500.00');
        $token = $this->merchantLogin($merchant);
        $success = $this->createOrder($merchant, Order::STATUS_SUCCESS);
        $this->createOrder($merchant, Order::STATUS_FAILED);
        $this->createOrder($other, Order::STATUS_SUCCESS);

        $created = $this->jsonRequest('POST', '/merchant/exports', $token, ['type' => 'orders', 'filters' => ['status' => 'success', 'cost_price' => '1', 'page' => 3]]);
        $this->assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        $record = $this->body($created);
        $this->assertSame('pending', $record['status']);
        $this->assertSame(['status' => 'success'], $record['filters'], '只留认识的筛选条件');
        $this->assertSame([$record['id']], $this->dispatched);
        $this->assertSame(422, $this->jsonRequest('GET', "/merchant/exports/{$record['id']}/download", $token)->getStatusCode(), '还没生成好');

        make(MerchantExportService::class)->generate($record['id']);

        $list = $this->body($this->jsonRequest('GET', '/merchant/exports', $token));
        $this->assertSame(1, $list['total']);
        $this->assertSame('success', $list['data'][0]['status']);
        $this->assertSame(1, $list['data'][0]['row_count']);

        $download = $this->jsonRequest('GET', "/merchant/exports/{$record['id']}/download", $token);
        $this->assertSame(200, $download->getStatusCode());
        $this->assertStringContainsString("filename*=UTF-8''" . rawurlencode($record['file_name']), $download->getHeaderLine('Content-Disposition'));
        $csv = $this->fileBody($download);
        $this->assertStringStartsWith("\xEF\xBB\xBF平台订单号,", $csv);
        $lines = array_values(array_filter(explode("\n", $csv)));
        $this->assertCount(2, $lines, '表头 + 一行成功订单');
        $this->assertStringContainsString($success->order_no . "\t", $lines[1]);
        $this->assertStringContainsString('成功', $lines[1]);
        $this->assertStringNotContainsString('98.00', $csv, '不导出成本');

        // 别的商户看不到、下载不了
        $otherToken = $this->merchantLogin($other);
        $this->assertSame([], $this->body($this->jsonRequest('GET', '/merchant/exports', $otherToken))['data']);
        $this->assertSame(404, $this->jsonRequest('GET', "/merchant/exports/{$record['id']}/download", $otherToken)->getStatusCode());
    }

    public function testExportBalanceLogsAndLimits()
    {
        $merchant = $this->createMerchant('500.00');
        $token = $this->merchantLogin($merchant);
        $order = $this->createOrder($merchant, Order::STATUS_SUCCESS);

        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/exports', $token, ['type' => 'nope'])->getStatusCode());
        $record = $this->body($this->jsonRequest('POST', '/merchant/exports', $token, ['type' => 'balance_logs', 'filters' => ['type' => 'order_pay']]));
        make(MerchantExportService::class)->generate($record['id']);
        $csv = $this->fileBody($this->jsonRequest('GET', "/merchant/exports/{$record['id']}/download", $token));
        $this->assertStringContainsString('下单扣款,-99.50,400.50,"' . $order->order_no . "\t\"", $csv);

        // 同时最多 3 个生成中的
        for ($i = 0; $i < 3; ++$i) {
            $this->assertSame(200, $this->jsonRequest('POST', '/merchant/exports', $token, ['type' => 'orders'])->getStatusCode());
        }
        $this->assertSame(422, $this->jsonRequest('POST', '/merchant/exports', $token, ['type' => 'orders'])->getStatusCode());
    }

    public function testCleanupRemovesExpiredRecordsAndFiles()
    {
        $merchant = $this->createMerchant('500.00');
        $token = $this->merchantLogin($merchant);
        $this->createOrder($merchant, Order::STATUS_SUCCESS);
        $old = $this->body($this->jsonRequest('POST', '/merchant/exports', $token, ['type' => 'orders']));
        $fresh = $this->body($this->jsonRequest('POST', '/merchant/exports', $token, ['type' => 'orders']));
        $service = make(MerchantExportService::class);
        $service->generate($old['id']);
        $service->generate($fresh['id']);
        $oldPath = MerchantExportService::baseDir() . '/' . MerchantExport::find($old['id'])->file_path;
        $this->assertFileExists($oldPath);
        MerchantExport::where('id', $old['id'])->update(['created_at' => date('Y-m-d H:i:s', strtotime('-8 days'))]);

        $this->assertGreaterThanOrEqual(1, $service->cleanup());
        $this->assertNull(MerchantExport::find($old['id']));
        $this->assertFileDoesNotExist($oldPath);
        $this->assertNotNull(MerchantExport::find($fresh['id']), '没过期的不删');
    }

    /**
     * 下载走 SwooleFileStream（真实服务器用 sendfile 发文件），测试客户端拿到的 body 是文件路径。
     * @param mixed $response
     */
    private function fileBody($response): string
    {
        $body = (string) $response->getBody();

        return str_starts_with($body, MerchantExportService::baseDir()) && is_file($body) ? file_get_contents($body) : $body;
    }

    private function merchantLogin(Merchant $merchant): string
    {
        $user = MerchantUser::create([
            'merchant_id' => $merchant->id,
            'username' => $this->uniq('mu_'),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => 'active',
        ]);
        $response = $this->client->request('POST', '/merchant/auth/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['username' => $user->username, 'password' => self::PASSWORD],
        ]);

        return $this->body($response)['token'];
    }

    private function createOrder(Merchant $merchant, string $status): Order
    {
        $order = Order::create([
            'order_no' => date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => $this->uniq('M'),
            'product_id' => 0,
            'product_code' => 'P',
            'product_name' => '话费100',
            'mobile' => '1009' . random_int(1000000, 9999999),
            'operator' => 'cmcc',
            'province' => '广东',
            'face_value' => 100,
            'sale_price' => '99.50',
            'cost_price' => '98.00',
            'status' => $status,
            'notify_status' => 'none',
            'finished_at' => $status === Order::STATUS_PENDING ? null : date('Y-m-d H:i:s'),
        ]);
        MerchantBalanceLog::create([
            'merchant_id' => $merchant->id, 'type' => 'order_pay', 'amount' => '-99.50', 'balance_after' => '400.50',
            'order_id' => $order->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $order;
    }
}
