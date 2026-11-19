# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

话费充值平台：Hyperf 3.1 + PHP 8.4 + Swoole 后端，两个 Vue 3 + TypeScript + Vite + Element Plus 前端（`web/` 管理后台、`merchant-web/` 商户后台）。需求与设计以 [docs/project.md](docs/project.md) 为准，商户接入文档是 [docs/api.md](docs/api.md)。

**写或改 PHP 代码前先加载 `hyperf-conventions` skill**（[.claude/skills/hyperf-conventions/SKILL.md](.claude/skills/hyperf-conventions/SKILL.md)），里面有完整的编码规范、注解要求、测试写法和「改了什么要跑哪些测试」的判断规则，本文件不重复。

## 常用命令

本机没有 PHP / Composer，所有 PHP 命令都在 Docker 容器 `pb` 里跑（宿主机端口 9502）。

```bash
docker compose up -d                                   # 启动
docker compose restart                                 # 改完 PHP 代码必须重启（Swoole 常驻内存）
docker exec pb rm -rf runtime/container                # 加了新类 / 注解 / 改了依赖注入后，先清再重启
docker exec pb composer test                           # 全部测试（1～2 分钟）
docker exec pb vendor/bin/co-phpunit --prepend test/bootstrap.php -c phpunit.xml.dist test/Cases/Xxx/XxxTest.php   # 单个测试文件
docker exec pb composer analyse                        # phpstan，改了 PHP 都要跑
docker exec pb vendor/bin/php-cs-fixer fix <files> --config=.php-cs-fixer.php   # 只格式化改动的文件
docker exec pb php bin/hyperf.php migrate              # 数据库迁移
docker exec pb php bin/hyperf.php admin:sync-permissions   # 新增权限编码后执行
```

前端（两个项目命令一样）：

```bash
npm --prefix web run dev            # http://localhost:5175/admin/，/api/* 代理到 127.0.0.1:9502 并去掉 /api 前缀
npm --prefix merchant-web run dev   # http://localhost:5176/merchant/
npm --prefix web run type-check
npm --prefix web run build          # vue-tsc + vite build，产物在 dist/
```

`.claude/launch.json` 里有 `web-admin` 和 `merchant-web` 两个预览配置。

## 架构

### 后端分层

`Controller → Service → Dao → Model`，只调相邻下一层；所有数据库查询在 Dao。路由、依赖注入、定时任务、进程、命令全部用注解（`config/routes.php` 不再加路由）。

三套入口、三套鉴权，互相独立：

| 入口 | 路由前缀 | 鉴权 |
|---|---|---|
| 管理后台 `app/Controller/Admin/` | `/admin` | `AdminAuthMiddleware`（JWT，`ADMIN_JWT_SECRET`）+ `AdminPermissionMiddleware`（`#[RequiresPermission]`） |
| 商户后台 `app/Controller/Merchant/` | `/merchant` | `MerchantAuthMiddleware`（JWT，`MERCHANT_JWT_SECRET`） |
| 商户开放接口 `app/Controller/OpenApi/` | `/open/v1` | `OpenApiAuthMiddleware`（AppKey + 签名 `App\Security\OpenApiSigner` + IP 白名单） |
| 供应商回调 `SupplierNotifyController` | `/notify/supplier/{code}` | 由对应驱动验签 |

- 带 `#[RequiresPermission]` 的写操作由 `AdminOperationLogAspect` 自动记操作日志。
- 新增权限编码：加进 `App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS`，同步 README「接口一览」，部署后跑 `admin:sync-permissions`。前端菜单（`web/src/layout/menus.ts`）的 `permission` 填对应查看权限。

### 订单主流程（跨多个文件，改之前先通读）

1. **下单** `Service/Order/OrderService::create`：校验 → 号段识别运营商 / 省份（`MobileSegmentService`）→ 商户定价（`MerchantPriceService`）→ 选路预检 → 黑名单 / 通道维护 → 扣款建单（`MerchantBalanceService`，事务 + `lockForUpdate`）→ 事务提交后推 `SubmitOrderJob`。
2. **选路** `Service/Product/ProductRouteService::candidates`：平台商品绑定的供应商商品，按运营商、省份、启停、通道维护过滤（规则见 docs/project.md 2.1）。
3. **提交** `OrderProcessService::submit` → `Service/Supplier/SupplierGateway`（统一出口：找驱动、解密配置、吞异常、记 `supplier` 日志）→ `app/Supplier/Driver/*Driver`。每次提交写一条 `order_attempts`。
4. **拿结果**：供应商回调（`SupplierCallbackService`）或 `OrderCrontab` 每分钟查单 → `OrderProcessService::applyResult` → 成功 / 换下一个供应商 / 全部失败退款。超时转 `timeout`，没提交出去的转 `abnormal`（异常订单，人工处理）。
5. **通知商户** `MerchantNotifyService` 走独立的 `notify` 队列（`NotifyQueueConsumerProcess`，进程名 `async-queue-notify`），商户接口慢不影响充值。

核心不变量（`OrderProcessService` 顶部注释）：

- **防重复充值**：一个订单同一时间最多一次「处理中」的提交；结果没出来绝不换供应商。驱动拿不准、网络超时一律当 `Processing`，绝不当失败。
- 状态变更全是带条件的更新（`where status = 预期`，影响 1 行才算成功），回调 / 查单 / 人工操作并发到达只生效一次。
- 退款和订单改失败在同一个事务里；异步任务一律在事务提交后再推。
- 订单状态：`pending / processing / timeout / abnormal / success / failed`（`App\Model\Order`）。

### 供应商驱动

新接一家：在 `app/Supplier/Driver/` 写实现 `SupplierDriverInterface` 的类，登记到 `SupplierDriverRegistry::DRIVERS`，写 `test/Cases/Supplier/XxxDriverTest.php`（外部请求全 mock）。

- 接口约定见 `SupplierDriverInterface` 注释：只有上游明确成功 / 失败才返回 Success / Failed；每次调用 10 秒内返回；`code()` 上线后不能改。
- `configSchema()` 决定后台表单，`secret` 类型字段用 `APP_ENCRYPTION_KEY` 加密存储（`App\Security\Encrypter`，上线后不能换 key）。
- 每家的上游文档在 `docs/` 下，对接规则写在 docs/project.md 第 7 章（7.1～7.12），README 有对应关系；新接一家要同步这两处。

### 缓存与后台进程

- 号段、商户、商户价格、选路配置、通道维护、黑名单（Redis 集合 `blacklist:mobiles`）都走 Redis 缓存（`c:` 前缀），后台改动后由对应 Service 主动清；改这些配置相关代码时确认清缓存的调用还在（尤其要在事务提交后清）。
- 必须存在的进程：`crontab-dispatcher`、`async-queue`、`async-queue-notify`（`app/Process/`），缺了定时任务和队列静默不执行，排查先看 `docker exec pb ps aux`。
- 定时任务在 `app/Crontab/`：订单兜底、商品 / 省份统计、余额告警、商户消耗、导出清理等；对应的手动重算命令在 `app/Command/`（`stats:product-daily`、`stats:province-daily`、`segment:import`、`data:clean-orders-products` 等，用法见 README）。
- 日志渠道：`supplier`、`order` 等，写到 `runtime/logs/`。

### 金额

金额一律字符串 + bcmath（保留 2 位），数据库 `decimal`。资金变动必须写 `merchant_balance_logs` 流水。

## 前端

`web/` 和 `merchant-web/` 结构相同（`src/api`、`src/views`、`src/router`、`src/layout`、`src/stores`），分开构建、分开部署（`/admin/`、`/merchant/`）。新增管理端页面：`src/api/xxx.ts` → `src/views/xxx/XxxView.vue` → `src/router/index.ts`（`meta.title`）→ `src/layout/menus.ts`。

`merchant-web/.env` 不在仓库里，本机构建时用内联环境变量（`VITE_APP_TITLE`、`VITE_API_BASE_URL=/api`）。

## 打包与部署

- 「打包」= `release/` 下的 `server.tar.gz`（`git archive --prefix=phoneBill/ HEAD`，不含 `web`、`merchant-web`）、`admin.tar.gz`、`merchant.tar.gz`（构建后的 dist，顶层目录 `admin/`、`merchant/`）。`release/` 已忽略。nginx 配置见 `deploy/nginx/`。
- 部署要点（`.env` 必填项、`TRUSTED_PROXIES` 等）见 README「部署要点」；新增配置项要同步 `.env.example`（填占位值）。
