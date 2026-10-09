# phoneBill

Hyperf 3.1 + PHP 8.4 + Swoole 后端，附带 Web 管理后台（Vue 3 + TypeScript + Vite + Element Plus）。

话费充值平台，需求与设计见 [docs/project.md](docs/project.md)。

管理后台的基础能力：

- 管理员登录（JWT，8 小时有效，改密码后旧登录全部失效）
- 管理员账号：新建、编辑、启用/禁用、重置密码
- 角色权限（RBAC）：自定义角色、勾选权限，防提权（不能授予自己没有的权限、不能改自己所在角色）
- 操作日志：所有带权限的写操作自动记录，敏感字段打码
- 定时任务（`hyperf/crontab`）、异步队列（`hyperf/async-queue`）进程已注册

已完成的业务模块。第一阶段：

- 商品中心：供应商（接口参数加密存储）、供应商商品、平台商品（绑定供应商商品、按运营商的默认售价）
- 风控：号段查询（识别运营商和省份，并列出每个商品的选路结果）
- 新接一家供应商：在 `app/Supplier/Driver/` 写驱动类，登记到 `App\Supplier\SupplierDriverRegistry::DRIVERS`
- 已有驱动：`mock` 模拟供应商；`open_platform` 开放平台话费（上游文档 [docs/open-api-integration.md](docs/open-api-integration.md)，对接规则见 [docs/project.md](docs/project.md) 7.1）；`shangteng` 商腾科技话费（上游文档 [docs/API接口.md](docs/API接口.md)，对接规则见 [docs/project.md](docs/project.md) 7.2）；`zhongkong` 中控话费（上游文档 [docs/中控API接口.md](docs/中控API接口.md)，对接规则见 [docs/project.md](docs/project.md) 7.3）

第二阶段：

- 商户中心：商户（AppKey/AppSecret、IP 白名单、通知地址）、按运营商定价、加减款、资金流水
- 风控：号码黑名单、通道维护
- 订单：开放接口下单（商户接入文档 [docs/api.md](docs/api.md)）、扣款、提交供应商、失败换供应商、回调、查单、超时转异常、失败退款、通知商户
- 订单中心：订单列表与详情、异常订单、人工查单 / 置成功 / 置失败 / 冲正 / 重发通知、导出 CSV
- 定时任务 `OrderCrontab` 每分钟查单、转异常、补推丢了的提交；日志渠道 `supplier`、`order`

第三阶段：

- 商户后台 `merchant-web/`：登录、首页（余额和今日数据）、订单记录、资金流水、账户（接入信息和可下单商品）
- 管理端「商户」页面可以给商户开后台账号、重置密码、启用禁用

## 目录

| 目录 | 说明 |
|---|---|
| `app/` | 后端代码，分层 `Controller → Service → Dao → Model` |
| `migrations/` | 数据库迁移 |
| `test/` | 测试（`test/Cases/`） |
| `web/` | 管理后台前端（平台内部用） |
| `merchant-web/` | 商户后台前端（商户用，只读） |
| `docs/` | 项目文档 [project.md](docs/project.md)、商户接入文档 [api.md](docs/api.md) |

## 后端本地开发

本机不装 PHP，所有 PHP 命令在 Docker 容器 `pb` 里执行。

```bash
cp .env.example .env                                   # 填数据库、Redis、ADMIN_JWT_SECRET
docker compose up -d                                   # 启动，宿主机端口 9502
docker exec pb php bin/hyperf.php migrate              # 执行数据库迁移
docker exec pb php bin/hyperf.php admin:create --username=admin --password=你的密码
docker compose restart                                 # 改完代码后重启生效（Swoole 常驻内存）
docker exec pb composer test                           # 全部测试
docker exec pb composer analyse                        # 静态分析
```

导入号段库（Navicat 导出的 `recharge_phone_prefix_info` 表，文件较大不进仓库，放在 `docs/` 下即可）：

```bash
docker exec pb php bin/hyperf.php segment:import docs/recharge_phone_prefix_info.sql --dry-run   # 先看统计
docker exec pb php bin/hyperf.php segment:import docs/recharge_phone_prefix_info.sql             # 按号段覆盖写入，可重复执行
```

商品每日统计（成功率、耗时）每天 3 点自动算前一天；人工处理完异常订单、或补历史数据时重算：

```bash
docker exec pb php bin/hyperf.php stats:product-daily 2026-10-05 --days=7   # 2026-09-29 到 2026-10-05，覆盖原结果
```

新增 `#[RequiresPermission('xxx.yyy')]` 后，把编码加进 `App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS`，
部署后执行 `docker exec pb php bin/hyperf.php admin:sync-permissions`，超级管理员才能拿到新权限。

编码规范见 [.claude/skills/hyperf-conventions/SKILL.md](.claude/skills/hyperf-conventions/SKILL.md)。

## 管理后台（web/）

```bash
cd web
npm install
npm run dev          # http://localhost:5175/admin/ ，/api/* 代理到 http://127.0.0.1:9502 并去掉 /api 前缀
npm run type-check   # 类型检查
npm run build        # 产物在 web/dist，部署在 /admin/ 路径下
```

生产环境在 nginx 里同样把 `/api/` 转发到后端并去掉 `/api` 前缀。

新增一个页面：

1. `src/api/xxx.ts` 定义接口
2. `src/views/xxx/XxxView.vue` 写页面
3. `src/router/index.ts` 加路由（`meta.title` 显示在顶栏和浏览器标题）
4. `src/layout/menus.ts` 加菜单项，`permission` 填后端对应的查看权限编码

## 商户后台（merchant-web/）

独立的前端项目，技术栈和 `web/` 一样，和管理端分开构建、分开部署（管理端可以只放内网）。

```bash
cd merchant-web
npm install
npm run dev          # http://localhost:5176/merchant/ ，/api/* 同样代理到后端
npm run build        # 产物在 merchant-web/dist，部署在 /merchant/ 路径下（或单独的子域名）
```

商户账号由平台在管理后台「商户 → 后台账号」里开通，登录体系和管理端完全分开（`MERCHANT_JWT_SECRET`），
同一账号 15 分钟内登录失败 10 次会暂时锁住。

## 接口一览

| 方法 | 路径 | 权限 |
|---|---|---|
| GET | `/` | 公开，健康检查 |
| POST | `/admin/auth/login` | 公开 |
| GET | `/admin/auth/me` | 登录即可 |
| PUT | `/admin/auth/password` | 登录即可 |
| GET | `/admin/admin-users`、`/admin/admin-users/role-options` | `admin_user.view` |
| POST/PUT | `/admin/admin-users`、`/{id}`、`/{id}/status`、`/{id}/password` | `admin_user.manage` |
| GET | `/admin/roles`、`/admin/roles/permissions` | `role.view` |
| POST/PUT/DELETE | `/admin/roles`、`/admin/roles/{id}` | `role.manage` |
| GET | `/admin/operation-logs` | `operation_log.view` |
| GET | `/admin/suppliers`、`/admin/suppliers/meta` | `supplier.view` |
| POST/PUT | `/admin/suppliers`、`/{id}`、`/{id}/status` | `supplier.manage` |
| GET | `/admin/supplier-products`、`/admin/supplier-products/supplier-options` | `supplier_product.view` |
| POST/PUT | `/admin/supplier-products`、`/{id}`、`/{id}/status` | `supplier_product.manage` |
| GET | `/admin/products`、`/admin/products/supplier-product-options`、`/admin/product-stats` | `product.view` |
| POST/PUT | `/admin/products`、`/{id}`、`/{id}/status` | `product.manage` |
| GET | `/admin/segments/lookup` | `risk.view` |
| GET | `/admin/risk/blacklist`、`/admin/risk/maintenances`、`/admin/risk/maintenances/options` | `risk.view` |
| POST/DELETE | `/admin/risk/blacklist`、`/admin/risk/blacklist/{id}`、`/admin/risk/maintenances`、`/{id}/finish` | `risk.manage` |
| GET | `/admin/merchants`、`/options`、`/{id}/products`、`/{id}/available-products`、`/admin/balance-logs` | `merchant.view` |
| POST/PUT | `/admin/merchants`、`/{id}`、`/{id}/status`、`/{id}/secret` | `merchant.manage` |
| PUT | `/admin/merchants/{id}/products/{productId}` | `merchant.price` |
| POST | `/admin/merchants/{id}/balance` | `merchant.balance` |
| GET | `/admin/orders`、`/filter-options`、`/export`、`/{id}` | `order.view` |
| POST | `/admin/orders/{id}/query`、`/{id}/success`、`/{id}/fail`、`/{id}/notify` | `order.manage` |
| POST | `/admin/orders/{id}/reverse` | `order.reverse` |
| GET/POST | `/open/v1/recharge`、`/order`、`/balance`、`/products` | 商户签名，见 [docs/api.md](docs/api.md) |
| GET/POST | `/notify/supplier/{供应商编码}` | 供应商回调，由驱动验签 |
| GET | `/admin/merchants/{id}/users` | `merchant.view` |
| POST | `/admin/merchants/{id}/users`、`/{id}/users/{userId}/status`、`/{id}/users/{userId}/password` | `merchant.manage` |
| POST | `/merchant/auth/login` | 公开（商户后台） |
| GET/PUT | `/merchant/auth/me`、`/merchant/auth/password` | 商户登录 |
| GET | `/merchant/dashboard`、`/orders`、`/orders/{id}`、`/balance-logs`、`/account` | 商户登录，只能看自己的数据 |

## 部署要点

- `.env` 必须配置 `ADMIN_JWT_SECRET`、`MERCHANT_JWT_SECRET`（两个不要相同）、`APP_ENCRYPTION_KEY`（上线后不能更换），`DB_CHARSET=utf8mb4`
- 部署在反向代理后面时配置 `TRUSTED_PROXIES`，操作日志才能记到真实 IP
- 确认容器里有 `crontab-dispatcher`、`async-queue`（默认 2 个）、`async-queue-notify` 这几个进程，否则定时任务和异步队列不执行
- 部署后执行 `admin:sync-permissions`；这次加了登录态的 aud 校验，已登录的管理员需要重新登录一次

## 容量与调优

按每天几十万单（高峰每秒 30～50 单）设计，相关配置都在 `.env`：

| 配置 | 默认 | 说明 |
|---|---|---|
| `QUEUE_PROCESSES` × `QUEUE_CONCURRENCY` | 2 × 50 | 同时在提交供应商的订单数。处理吞吐 ≈ 这个数 ÷ 供应商平均耗时（秒） |
| `NOTIFY_QUEUE_CONCURRENCY` | 30 | 通知商户单独一个队列，商户接口慢不影响充值 |
| `DB_MAX_CONNECTIONS` | 64 | 每个进程的连接池上限，必须 ≥ `QUEUE_CONCURRENCY`（提交任务等供应商时一直占着连接）；MySQL 的 `max_connections` 要大于各进程实际用到的总和 |
| `DB_SQL_LOG` | false | 全量 SQL 日志（`runtime/logs`，渠道 sql），量很大，只在排查问题时临时打开 |
| `DB_SLOW_QUERY_MS` | 500 | 超过这个毫秒数的慢查询始终记一条 warning，不受 `DB_SQL_LOG` 影响；0 表示不记 |
| `REDIS_MAX_CONNECTIONS` | 32 | 每个进程的 Redis 连接池上限 |

缓存（Redis，`c:` 前缀）：

- 号段：按号段缓存 1 天，号段保存 / 删除时自动清；`segment:import` 导入完会整体清掉
- 商户（按 AppKey，不含余额）：5 分钟，商户保存时自动清
- 商户的商品价格：5 分钟，平台商品增改 / 上下架、商户开通或改价后整体清掉
- 选路（候选供应商）：配置部分按「商品 + 运营商 + 省份」缓存 1 小时，平台商品 / 供应商商品 / 供应商改动后整体清掉；
  通道维护单独缓存「还没结束的维护」，每次按当前时间判断，到点自动生效和恢复，新增或提前结束维护时清掉

- 号码黑名单：整个黑名单放在 Redis 集合 `blacklist:mobiles` 里，下单只查 Redis；加入 / 移出时版本号 +1，下一次查询从数据库重建，Redis 被清空也会自动重建，每小时还会整体重建一次

下单接口除了建单扣款、重复充值检查、商户订单号查重这几处，其余配置都从缓存读。

订单、资金流水列表在没指定日期、也不是按单号 / 手机号精确查找时，默认只查最近 7 天；未完成的订单（含异常订单）不受这个限制。

本地压测（MySQL 在局域网另一台机器，单次往返约 8 ms；模拟供应商每单耗时 1 秒）：

| 场景 | 下单接口 | 订单处理 |
|---|---|---|
| 10 个商户，5000 单，100 并发 | 224 单/秒，p50 389 ms，p99 1.2 s，全部成功 | 50 单/秒，余额全部对得上 |
| 1 个商户，3000 单，100 并发 | 36 单/秒（同一商户扣款要锁余额行，受数据库往返耗时限制） | 跟得上下单速度 |

生产环境数据库在同机房时单次往返通常 0.2～0.5 ms，单个商户的扣款上限会高一个数量级。
