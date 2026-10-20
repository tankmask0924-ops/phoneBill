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

namespace App\Controller\Admin;

use App\Annotation\RequiresPermission;
use App\Controller\AbstractController;
use App\Middleware\AdminAuthMiddleware;
use App\Middleware\AdminPermissionMiddleware;
use App\Model\AdminUser;
use App\Network\ClientIpResolver;
use App\Service\Admin\AlipayAccountAdminService;
use App\Service\Admin\MerchantAdminService;
use App\Service\Admin\MerchantProductAdminService;
use App\Service\Admin\MerchantUserAdminService;
use App\Service\Admin\ReceivingAccountAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 商户中心 - 商户、商户商品与价格、余额调整，规则见 MerchantAdminService / MerchantProductAdminService。
 */
#[Controller(prefix: '/admin/merchants')]
class MerchantController extends AbstractController
{
    #[Inject]
    protected MerchantAdminService $merchantAdminService;

    #[Inject]
    protected MerchantProductAdminService $merchantProductAdminService;

    #[Inject]
    protected MerchantUserAdminService $merchantUserAdminService;

    #[Inject]
    protected ReceivingAccountAdminService $receivingAccountService;

    #[Inject]
    protected AlipayAccountAdminService $alipayAccountService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->merchantAdminService->list($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.view')]
    #[GetMapping(path: 'options')]
    public function options(): array
    {
        return $this->merchantAdminService->options();
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.manage')]
    #[PostMapping(path: '')]
    public function store(): array
    {
        return $this->merchantAdminService->create($this->admin(), $this->request->all(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.manage')]
    #[PutMapping(path: '{id:\d+}')]
    public function update(int $id): array
    {
        return $this->merchantAdminService->update($this->admin(), $id, $this->request->all(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.manage')]
    #[PostMapping(path: '{id:\d+}/status')]
    public function changeStatus(int $id): array
    {
        return $this->merchantAdminService->changeStatus($this->admin(), $id, $this->request->input('status'), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.manage')]
    #[PostMapping(path: '{id:\d+}/secret')]
    public function resetSecret(int $id): array
    {
        return $this->merchantAdminService->resetSecret($this->admin(), $id, $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.balance')]
    #[PostMapping(path: '{id:\d+}/balance')]
    public function adjustBalance(int $id): array
    {
        return $this->merchantAdminService->adjustBalance($this->admin(), $id, $this->request->all(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.view')]
    #[GetMapping(path: '{id:\d+}/products')]
    public function products(int $id): array
    {
        return $this->merchantProductAdminService->list($id);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.view')]
    #[GetMapping(path: '{id:\d+}/available-products')]
    public function availableProducts(int $id): array
    {
        return $this->merchantProductAdminService->available($id);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.price')]
    #[PutMapping(path: '{id:\d+}/products/{productId:\d+}')]
    public function saveProduct(int $id, int $productId): array
    {
        return $this->merchantProductAdminService->save($this->admin(), $id, $productId, $this->request->all(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.view')]
    #[GetMapping(path: '{id:\d+}/users')]
    public function users(int $id): array
    {
        return $this->merchantUserAdminService->list($id);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.manage')]
    #[PostMapping(path: '{id:\d+}/users')]
    public function createUser(int $id): array
    {
        return $this->merchantUserAdminService->create($this->admin(), $id, $this->request->all(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.manage')]
    #[PostMapping(path: '{id:\d+}/users/{userId:\d+}/status')]
    public function changeUserStatus(int $id, int $userId): array
    {
        return $this->merchantUserAdminService->changeStatus($this->admin(), $id, $userId, $this->request->input('status'), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.manage')]
    #[PostMapping(path: '{id:\d+}/users/{userId:\d+}/password')]
    public function resetUserPassword(int $id, int $userId): array
    {
        $this->merchantUserAdminService->resetPassword($this->admin(), $id, $userId, $this->request->input('password'), $this->clientIp());

        return ['success' => true];
    }

    /**
     * 商户的收款配置：全部收款账户，标出这个商户能用的。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.view')]
    #[GetMapping(path: '{id:\d+}/receiving-accounts')]
    public function receivingAccounts(int $id): array
    {
        return $this->receivingAccountService->forMerchant($id);
    }

    /**
     * 设置这个商户只能看到、选用哪几个收款账户。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.config')]
    #[PutMapping(path: '{id:\d+}/receiving-accounts')]
    public function assignReceivingAccounts(int $id): array
    {
        return $this->receivingAccountService->assignToMerchant($this->admin(), $id, $this->request->input('receiving_account_ids'), $this->clientIp());
    }

    /**
     * 商户的支付宝转账加款账户：指定的是哪个，和全部账户。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.view')]
    #[GetMapping(path: '{id:\d+}/alipay-account')]
    public function alipayAccount(int $id): array
    {
        return $this->alipayAccountService->forMerchant($id);
    }

    /**
     * 指定这个商户用哪个支付宝转账加款账户，alipay_account_id 为空表示不能用。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('funding.config')]
    #[PutMapping(path: '{id:\d+}/alipay-account')]
    public function bindAlipayAccount(int $id): array
    {
        return $this->alipayAccountService->bindMerchant($this->admin(), $id, $this->request->input('alipay_account_id'), $this->clientIp());
    }

    private function admin(): AdminUser
    {
        /* @var AdminUser */
        return $this->request->getAttribute('admin');
    }

    private function clientIp(): ?string
    {
        return $this->clientIpResolver->resolve($this->request);
    }
}
