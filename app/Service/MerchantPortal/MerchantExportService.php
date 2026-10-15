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

namespace App\Service\MerchantPortal;

use App\Dao\MerchantDao;
use App\Dao\MerchantExportDao;
use App\Enum\Operator;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantExport;
use App\Model\MerchantUser;
use App\Model\Order;
use App\Service\AbstractService;
use Hyperf\Database\Model\Collection;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Logger\LoggerFactory;
use RuntimeException;
use Throwable;

/**
 * 商户后台导出：订单记录、资金流水按当时的筛选条件异步生成 CSV（带 BOM，Excel 直接打开）。
 *
 * - 文件存在服务器本地 runtime/exports/{商户 id}/{日期}/ 下，保留 KEEP_DAYS 天，过期由 MerchantExportCleanupCrontab 连记录一起删；
 * - 一个商户同时最多 MAX_PENDING 个生成中的任务，一次最多 MAX_ROWS 行；
 * - 筛选和列表完全一致（MerchantPortalService::filteredOrders() / filteredBalanceLogs()），只能导出自己的数据。
 */
class MerchantExportService extends AbstractService
{
    public const KEEP_DAYS = 7;

    public const MAX_ROWS = 100000;

    private const MAX_PENDING = 3;

    private const MAX_PER_PAGE = 100;

    private const CHUNK = 1000;

    private const TYPE_LABELS = [
        MerchantExport::TYPE_ORDERS => '订单记录',
        MerchantExport::TYPE_BALANCE_LOGS => '资金流水',
    ];

    /** 各类型允许带的筛选条件，跟列表接口一样 */
    private const FILTER_KEYS = [
        MerchantExport::TYPE_ORDERS => ['keyword', 'mobile', 'status', 'operator', 'province', 'face_value', 'category_id', 'created_from', 'created_to'],
        MerchantExport::TYPE_BALANCE_LOGS => ['type', 'order_no', 'created_from', 'created_to'],
    ];

    private const ORDER_STATUS_LABELS = ['processing' => '充值中', 'abnormal' => '异常', 'success' => '成功', 'failed' => '失败（已退款）'];

    private const BALANCE_TYPE_LABELS = [
        MerchantBalanceLog::TYPE_RECHARGE => '加款',
        MerchantBalanceLog::TYPE_DEDUCT => '扣款',
        MerchantBalanceLog::TYPE_ORDER_PAY => '下单扣款',
        MerchantBalanceLog::TYPE_ORDER_REFUND => '失败退款',
    ];

    #[Inject]
    protected MerchantExportDao $exportDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantPortalService $portalService;

    #[Inject]
    protected ExportDispatcher $dispatcher;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 建一条生成中的记录，推队列异步生成。
     *
     * @return array<string, mixed>
     */
    public function create(Merchant $merchant, MerchantUser $user, mixed $type, mixed $filters): array
    {
        if (! is_string($type) || ! isset(self::TYPE_LABELS[$type])) {
            throw new HttpException(422, '导出类型不对');
        }
        $pending = $this->exportDao->countPending($merchant->id);
        if ($pending >= self::MAX_PENDING) {
            throw new HttpException(422, "还有 {$pending} 个导出正在生成，请稍后再试");
        }

        $export = $this->exportDao->create([
            'merchant_id' => $merchant->id,
            'merchant_user_id' => $user->id,
            'type' => $type,
            'filters' => $this->sanitizeFilters($type, $filters),
            'status' => MerchantExport::STATUS_PENDING,
            'file_name' => self::TYPE_LABELS[$type] . '-' . date('YmdHis') . '.csv',
        ]);
        $this->dispatcher->generate($export->id);

        return $this->format($export);
    }

    /**
     * @param array<string, mixed> $query page / per_page
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(Merchant $merchant, array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));
        $builder = $this->exportDao->newQuery()->where('merchant_id', $merchant->id);
        $total = (clone $builder)->count();

        return [
            'data' => $builder->orderByDesc('id')->forPage($page, $perPage)->get()
                ->map(fn (MerchantExport $e) => $this->format($e))->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 下载用：文件的绝对路径和文件名。别的商户的记录一律当不存在。
     *
     * @return array{path: string, file_name: string}
     */
    public function file(Merchant $merchant, int $id): array
    {
        $export = $this->exportDao->findForMerchant($merchant->id, $id);
        if ($export === null) {
            throw new HttpException(404, '导出记录不存在');
        }
        if ($export->status !== MerchantExport::STATUS_SUCCESS) {
            throw new HttpException(422, $export->status === MerchantExport::STATUS_PENDING ? '文件还在生成，请稍后再试' : '这次导出失败了，请重新导出');
        }
        $path = $export->file_path === null ? null : self::baseDir() . '/' . $export->file_path;
        if ($path === null || ! is_file($path)) {
            throw new HttpException(404, '文件已过期，请重新导出');
        }

        return ['path' => $path, 'file_name' => $export->file_name];
    }

    /**
     * 队列里执行：写 CSV，成功或失败都更新记录。
     */
    public function generate(int $id): void
    {
        $export = $this->exportDao->find($id);
        if ($export === null || $export->status !== MerchantExport::STATUS_PENDING) {
            return;
        }
        $relative = $export->merchant_id . '/' . date('Ymd') . '/' . bin2hex(random_bytes(16)) . '.csv';
        $path = self::baseDir() . '/' . $relative;
        try {
            $merchant = $this->merchantDao->find($export->merchant_id);
            if ($merchant === null) {
                throw new HttpException(404, '商户不存在');
            }
            $rows = $this->write($merchant, $export, $path);
            $this->exportDao->finish($export->id, [
                'status' => MerchantExport::STATUS_SUCCESS,
                'file_path' => $relative,
                'row_count' => $rows,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            is_file($path) && unlink($path);
            $this->loggerFactory->get('export')->error('导出失败', ['export_id' => $export->id, 'error' => $e->getMessage()]);
            $this->exportDao->finish($export->id, [
                'status' => MerchantExport::STATUS_FAILED,
                // 业务上的原因（比如行数太多）直接给商户看，其他错误不暴露细节
                'error' => $e instanceof HttpException ? mb_substr($e->getMessage(), 0, 255) : '生成失败，请重新导出',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * 删掉过期的记录和文件，返回删掉的记录数。
     */
    public function cleanup(): int
    {
        $before = date('Y-m-d H:i:s', strtotime('-' . self::KEEP_DAYS . ' days'));
        $deleted = 0;
        do {
            $rows = $this->exportDao->createdBefore($before, 500);
            foreach ($rows as $export) {
                $path = $export->file_path === null ? null : self::baseDir() . '/' . $export->file_path;
                if ($path !== null && is_file($path)) {
                    unlink($path);
                }
                $this->exportDao->delete($export->id);
                ++$deleted;
            }
        } while ($rows->count() === 500);
        $this->removeEmptyDateDirs();

        return $deleted;
    }

    public static function baseDir(): string
    {
        return BASE_PATH . '/runtime/exports';
    }

    /**
     * 只留这个类型认识的条件，值只能是字符串或数字。
     *
     * @return array<string, int|string>
     */
    private function sanitizeFilters(string $type, mixed $filters): array
    {
        $result = [];
        if (! is_array($filters)) {
            return $result;
        }
        foreach (self::FILTER_KEYS[$type] as $key) {
            $value = $filters[$key] ?? null;
            if (is_int($value) || (is_string($value) && trim($value) !== '')) {
                $result[$key] = is_string($value) ? mb_substr(trim($value), 0, 5000) : $value;
            }
        }

        return $result;
    }

    /**
     * @return int 写入的数据行数
     */
    private function write(Merchant $merchant, MerchantExport $export, string $path): int
    {
        $builder = $export->type === MerchantExport::TYPE_ORDERS
            ? $this->portalService->filteredOrders($merchant, $export->filters)
            : $this->portalService->filteredBalanceLogs($merchant, $export->filters);
        if ((clone $builder)->count() > self::MAX_ROWS) {
            throw new HttpException(422, '超过 ' . self::MAX_ROWS . ' 条，请缩小时间范围后重新导出');
        }

        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("创建目录失败：{$dir}");
        }
        $out = fopen($path, 'w');
        if ($out === false) {
            throw new RuntimeException("打开文件失败：{$path}");
        }
        try {
            // 带 BOM，Excel 才认得 UTF-8 中文
            fwrite($out, "\xEF\xBB\xBF");
            $rows = 0;
            if ($export->type === MerchantExport::TYPE_ORDERS) {
                $this->putRow($out, ['平台订单号', '商户订单号', '商品', '手机号', '运营商', '省份', '面值', '扣款金额', '状态', '失败原因', '下单时间', '完成时间', '耗时（秒）']);
                $builder->chunkByIdDesc(self::CHUNK, function (Collection $orders) use ($out, &$rows) {
                    $presented = $this->portalService->presentOrders($orders->all());
                    foreach ($orders->values() as $i => $order) {
                        $this->putRow($out, $this->orderRow($order, $presented[$i]));
                        ++$rows;
                    }
                });
            } else {
                $this->putRow($out, ['时间', '类型', '金额', '变动后余额', '平台订单号', '商户订单号', '手机号', '备注']);
                $builder->chunkByIdDesc(self::CHUNK, function (Collection $logs) use ($merchant, $out, &$rows) {
                    foreach ($this->portalService->presentBalanceLogs($merchant, $logs->all()) as $l) {
                        $this->putRow($out, [
                            $l['created_at'], self::BALANCE_TYPE_LABELS[$l['type']] ?? $l['type'], $l['amount'], $l['balance_after'],
                            self::text($l['order_no']), self::text($l['merchant_order_no']), self::text($l['mobile']), $l['remark'] ?? '',
                        ]);
                        ++$rows;
                    }
                });
            }
        } finally {
            fclose($out);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $o MerchantPortalService::presentOrders() 的一行
     * @return list<int|string>
     */
    private function orderRow(Order $order, array $o): array
    {
        $duration = $o['finished_at'] !== null && $order->created_at !== null && $order->finished_at !== null
            ? max(0, $order->finished_at->getTimestamp() - $order->created_at->getTimestamp())
            : '';

        return [
            self::text($o['order_no']), self::text($o['merchant_order_no']), $o['product_name'], self::text($o['mobile']),
            Operator::tryFrom($o['operator'])?->label() ?? $o['operator'], $o['province'], $o['face_value'], $o['amount'],
            self::ORDER_STATUS_LABELS[$o['status']] ?? $o['status'], $o['fail_reason'] ?? '',
            $o['created_at'] ?? '', $o['finished_at'] ?? '', $duration,
        ];
    }

    /**
     * 长数字（订单号、手机号）后面加制表符，免得 Excel 转成科学计数法。
     */
    private static function text(?string $value): string
    {
        return $value === null || $value === '' ? '' : $value . "\t";
    }

    /**
     * @param resource $out
     * @param list<null|int|string> $row
     */
    private function putRow($out, array $row): void
    {
        fputcsv($out, $row, ',', '"', '');
    }

    /**
     * 删掉今天以前的空日期目录（今天的可能正在写，不动）。
     */
    private function removeEmptyDateDirs(): void
    {
        $today = date('Ymd');
        foreach (glob(self::baseDir() . '/*/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) < $today && (glob($dir . '/*') ?: []) === []) {
                rmdir($dir);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function format(MerchantExport $export): array
    {
        return [
            'id' => $export->id,
            'type' => $export->type,
            'type_label' => self::TYPE_LABELS[$export->type] ?? $export->type,
            'filters' => $export->filters,
            'status' => $export->status,
            'file_name' => $export->file_name,
            'row_count' => $export->row_count,
            'error' => $export->error,
            'created_at' => $export->created_at?->toDateTimeString(),
            'finished_at' => $export->finished_at?->toDateTimeString(),
            'expires_at' => $export->created_at?->copy()->addDays(self::KEEP_DAYS)->toDateTimeString(),
        ];
    }
}
