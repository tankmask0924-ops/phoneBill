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

第二阶段：

- 商户中心：商户（AppKey/AppSecret、IP 白名单、通知地址）、按运营商定价、加减款、资金流水
- 风控：号码黑名单、通道维护
- 订单：开放接口下单（商户接入文档 [docs/api.md](docs/api.md)）、扣款、提交供应商、失败换供应商、回调、查单、超时转异常、失败退款、通知商户
- 订单中心：订单列表与详情、异常订单、人工查单 / 置成功 / 置失败 / 冲正 / 重发通知、导出 CSV
- 定时任务 `OrderCrontab` 每分钟查单、转异常、补推丢了的提交；日志渠道 `supplier`、`order`

## 目录

| 目录 | 说明 |
|---|---|
| `app/` | 后端代码，分层 `Controller → Service → Dao → Model` |
| `migrations/` | 数据库迁移 |
| `test/` | 测试（`test/Cases/`） |
| `web/` | 管理后台前端 |

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
| GET | `/admin/products`、`/admin/products/supplier-product-options` | `product.view` |
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

## 部署要点

- `.env` 必须配置 `ADMIN_JWT_SECRET`、`APP_ENCRYPTION_KEY`（上线后不能更换），`DB_CHARSET=utf8mb4`
- 部署在反向代理后面时配置 `TRUSTED_PROXIES`，操作日志才能记到真实 IP
- 确认容器里有 `crontab-dispatcher` 和 `async-queue` 两个进程，否则定时任务和异步队列不执行
