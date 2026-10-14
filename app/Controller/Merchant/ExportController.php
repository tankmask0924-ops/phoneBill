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

namespace App\Controller\Merchant;

use App\Controller\AbstractController;
use App\Middleware\MerchantAuthMiddleware;
use App\Model\Merchant;
use App\Model\MerchantUser;
use App\Service\MerchantPortal\MerchantExportService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Stream\SwooleFileStream;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;

/**
 * 商户后台：导出订单记录、资金流水，导出列表和下载（规则见 MerchantExportService）。
 */
#[Controller(prefix: '/merchant/exports')]
#[Middleware(MerchantAuthMiddleware::class)]
class ExportController extends AbstractController
{
    #[Inject]
    protected MerchantExportService $exportService;

    /**
     * type：orders / balance_logs；filters：跟对应列表接口的筛选参数一样。
     */
    #[PostMapping(path: '')]
    public function store(): array
    {
        return $this->exportService->create($this->merchant(), $this->user(), $this->request->input('type'), $this->request->input('filters', []));
    }

    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->exportService->list($this->merchant(), $this->request->all());
    }

    #[GetMapping(path: '{id:\d+}/download')]
    public function download(int $id): ResponseInterface
    {
        $file = $this->exportService->file($this->merchant(), $id);

        return $this->response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            // 中文文件名按 RFC 5987 编码，filename 给老浏览器一个 ASCII 的兜底
            ->withHeader('Content-Disposition', 'attachment; filename="export-' . $id . '.csv"; filename*=UTF-8\'\'' . rawurlencode($file['file_name']))
            ->withBody(new SwooleFileStream($file['path']));
    }

    private function user(): MerchantUser
    {
        /* @var MerchantUser */
        return $this->request->getAttribute('merchant_user');
    }

    private function merchant(): Merchant
    {
        /* @var Merchant */
        return $this->request->getAttribute('merchant');
    }
}
