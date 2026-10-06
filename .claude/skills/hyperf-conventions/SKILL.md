---
name: hyperf-conventions
description: Coding standards and workflow conventions for this Hyperf PHP project (phoneBill). Use whenever writing, editing, or reviewing PHP code in this repo, or running tests/lint/static analysis.
---

# Hyperf 项目规范（phoneBill）

Hyperf 3.1 + PHP 8.4 + Swoole，基于 `hyperf/hyperf-skeleton`，跑在 Docker 容器 `pb` 里。

## 1. 环境与常用命令

本机**没有安装 PHP / Composer**，所有 PHP 命令必须带 `docker exec pb` 前缀在容器里执行。

| 做什么 | 命令 |
|---|---|
| 启动容器 | `docker compose up -d` |
| 改完代码让它生效 | `docker compose restart` |
| 加了新类、新注解或改了依赖注入后 | 先 `docker exec pb rm -rf runtime/container`，再 `docker compose restart` |
| 语法检查单个文件 | `docker exec pb php -l <file>` |
| 跑单个测试文件 | `docker exec pb vendor/bin/co-phpunit --prepend test/bootstrap.php -c phpunit.xml.dist <test file>` |
| 跑全部测试 | `docker exec pb composer test` |
| 格式化指定文件 | `docker exec pb vendor/bin/php-cs-fixer fix <files> --config=.php-cs-fixer.php` |
| 静态分析 | `docker exec pb composer analyse` |
| 执行数据库迁移 | `docker exec pb php bin/hyperf.php migrate` |

- **改完 PHP 代码必须 `docker compose restart` 才生效**：Swoole worker 常驻内存，不会自动重新加载。改完直接 curl 大概率跑的还是旧代码（日志时间戳没变就是这个原因）。
- 刚写完文件马上 `php -l` 偶尔会报解析错误，这是宿主机和容器之间的文件同步延迟，必须等 1～2 秒重新检查一次再下结论。

## 2. 代码风格

规则来源：[.php-cs-fixer.php](.php-cs-fixer.php)（`@PSR2` + `@Symfony` + `@DoctrineAnnotation` + `@PhpCsFixer`）。

- 所有文件开头必须有 `declare(strict_types=1);`
- 字符串统一用单引号，数组统一用短语法 `[]`
- `use` 按字母顺序排序，只保留实际用到的
- **类必须先写 `use` 再用短类名**。项目开了 `global_namespace_import`，cs-fix 遇到裸写的完整类名（`\Hyperf\Xxx\Yyy::class`）会自动补 `use`，但这个动作跟 `header_comment` 规则冲突，会把文件头版权注释复制成两份（已知 bug）。`config/autoload/*.php` 这类返回数组的配置文件同样适用。
- **命名空间函数必须先写 `use function` 再调用**，例如 `use function Hyperf\Support\env;`、`use function Hyperf\Support\make;`。不导入时 PHP 会去当前命名空间里找这个函数，运行到那一行才报「Call to undefined function」。
- 格式化只针对这次改动的文件（上面表格里的命令）。用 `composer cs-fix` 全项目扫描时，跑完必须 `git status` / `git diff` 检查，用 `git checkout -- <file>` 撤销无关文件的改动。
- phpstan 保持 `level: 0` 和现有配置（[phpstan.neon.dist](phpstan.neon.dist)），只有用户明确要求时才调整。

## 3. 分层与目录

分层：`Controller → Service → Dao → Model`，每一层只调用紧挨着的下一层。

- `app/Controller/` — 继承 `App\Controller\AbstractController`，只负责接收请求、调用 Service、返回响应；**业务逻辑和数据库查询必须交给 Service / Dao**
- `app/Service/` — 继承 `App\Service\AbstractService`，编排业务流程、组合多个 Dao
- `app/Dao/` — 继承 `App\Dao\AbstractDao`，设置 `protected string $model`；只做数据访问，**所有数据库查询都放在这里**
- `app/Model/` — 继承 `App\Model\Model`
- `app/Listener/`、`app/Exception/Handler/`、`app/Job/`、`app/Crontab/`、`app/Process/` — 监听器、异常处理器、队列任务、定时任务、进程
- `config/autoload/` — 集中放各类配置，业务代码里只做读取
- PSR-4：`App\` → `app/`

新增业务模块（以 `Xxx` 为例）：

1. `app/Model/Xxx.php` — 继承 `App\Model\Model`
2. `app/Dao/XxxDao.php` — 继承 `App\Dao\AbstractDao`，`protected string $model = Xxx::class;`
3. `app/Service/XxxService.php` — 继承 `App\Service\AbstractService`，`#[Inject]` 注入 `XxxDao`
4. `app/Controller/XxxController.php` — 继承 `App\Controller\AbstractController`，`#[Inject]` 注入 `XxxService`

## 4. 必须使用注解

注解扫描已开启（[config/autoload/annotations.php](config/autoload/annotations.php)，扫描 `app/`）。框架提供了注解的功能，新代码必须用注解实现：

| 场景 | 必须这样写 | 被它取代的旧写法（仅存量代码里有） |
|---|---|---|
| 路由 | `#[Controller(prefix: '/xxx')]` + `#[GetMapping]` / `#[PostMapping]` | `config/routes.php` 里 `Router::addRoute()` |
| 依赖注入 | `#[Inject]` 属性注入 | 手动 `$container->get()` |
| 事件监听 | `#[Listener]` | `config/autoload/listeners.php` 里罗列 |
| 定时任务 | `#[Crontab]` | — |
| 进程 | 自己写的进程用 `#[Process]`；框架自带的进程类必须先包一层子类，注解加在子类上（见第 6 节） | `config/autoload/processes.php` 里注册 |
| 方法级缓存 | Service 层用 `#[Cacheable]` / `#[CachePut]` / `#[CacheEvict]` | 手写 Redis 缓存 |
| Model 按主键缓存 | `hyperf/model-cache`（trait + 接口，见第 5 节） | 手写 Redis 缓存 |
| 命令行命令 | `#[Command]` | `config/autoload/commands.php` 里注册 |
| AOP 切面 | `#[Aspect]` | — |

`config/autoload/*.php` 里已有的旧注册项保持原样，只在顺带修改那块代码时再迁移成注解。

## 5. 数据访问、金额与并发

- 列表里给每行补关联数据时，先收集这一页的 id，`whereIn('id', $ids)->get()->keyBy('id')` 一次查出，再逐行取；不要逐行查库。
- 金额必须用字符串 + bcmath（`bcadd` / `bcsub` / `bccomp` / `bcmul`，保留 2 位）计算和比较，数据库列用 `decimal`。
- 状态流转用带条件的更新防并发：`where id = ? and status = 预期状态` 再 `update`，影响行数为 1 才算成功。
- 先读后写的资金操作必须在事务里先 `lockForUpdate()` 锁住相关行，锁内重新读取再判断。
- 要接 `hyperf/model-cache` 时，只给读多写少、所有修改都走模型 `save()` / `delete()` 的 Model 接；handler 用 `RedisStringHandler`（默认的 `RedisHandler` 会把 NULL 读回成 `''`）。

## 6. 队列、定时任务、日志

### 6.1 异步队列（hyperf/async-queue）

定义继承 `Hyperf\AsyncQueue\Job` 的类，实现 `handle()`；通过 `Hyperf\AsyncQueue\Driver\DriverFactory` 注入后 `->get('default')->push($job)` 推送。

- 消费者进程必须注册：框架的 `ConsumerProcess` 是 vendor 类，必须包一层子类再加 `#[Process(name: 'async-queue')]`，见 [QueueConsumerProcess](app/Process/QueueConsumerProcess.php)。
- Job 会被序列化进 Redis，构造参数只放标量（比如订单 id），服务在 `handle()` 里从容器取。

### 6.2 定时任务（hyperf/crontab）

在类上加 `#[Crontab(rule: '* * * * *', name: 'Xxx')]`，方法用 `__invoke()`（参考 [HeartbeatCrontab](app/Crontab/HeartbeatCrontab.php)）。

- 调度进程必须注册：`#[Crontab]` 只是登记任务，真正触发靠继承 `CrontabDispatcherProcess` 的进程。同样是 vendor 类，必须包一层子类再加 `#[Process(name: 'crontab-dispatcher')]`，见 [CrontabDispatcherProcess](app/Process/CrontabDispatcherProcess.php)。
- 缺了这个进程时 `#[Crontab]` 不报错也不执行。排查定时任务时必须先看 `docker exec pb ps aux` 里有没有 `crontab-dispatcher` 和 `async-queue` 两个进程。
- 多台部署时必须加 `onOneServer: true`；可能跑得比间隔久的任务加 `singleton: true` 并按最坏耗时设 `mutexExpires`。

### 6.3 日志

- 业务代码（Service、Job、Crontab 等）打日志必须用 `Hyperf\Logger\LoggerFactory` 注入后 `->get('渠道名')`。Monolog 按天切割写到 `runtime/logs/hyperf-YYYY-MM-DD.log`，保留 14 天（[config/autoload/logger.php](config/autoload/logger.php)）。
- `Hyperf\Contract\StdoutLoggerInterface` 只留给框架自身的启动、运维信息：它只写到 `docker logs`，不受切割和保留策略管，容器重启就没了。

## 7. 测试

- 测试放在 `test/Cases/`，命名空间 `HyperfTest\`；HTTP 测试继承 [test/HttpTestCase.php](test/HttpTestCase.php)。
- 测试库是共享的：断言必须只针对本用例建的数据（按本用例建的账号、角色过滤），`tearDown()` 里删掉本用例建的数据。
- **需要 mock 被注入的服务时，必须在 `setUp()` 里重建容器**：Controller 和 Service 在容器里是单例，第一个用例注入的 mock 会被带进后面的用例。写法：

```php
protected function setUp(): void
{
    ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
    ApplicationContext::getContainer()->get(ApplicationInterface::class);
    $this->client = make(Client::class);
}
```

  然后用 `ApplicationContext::getContainer()->set(接口或类::class, $mock)` 替换依赖。
- 调用外部接口的地方一律 mock，测试不发真实请求。
- [phpunit.xml.dist](phpunit.xml.dist) 里固定测试环境变量，新增影响测试行为的开关也在这里设。
- 列表类接口必须有一个「筛不出任何数据」的用例，断言返回 200 和空列表。

## 8. 配置、Docker、Git

**环境变量**

- 连接信息写在 `.env`（只留在本地，已在 `.gitignore` 里）。新增配置项必须同步更新 `.env.example`，里面一律填占位值。
- 读环境变量写在 `config/autoload/*.php` 里（`use function Hyperf\Support\env;`），业务代码通过 `ConfigInterface` 读配置。

**Docker**

- 容器名固定为 `pb`（[docker-compose.yml](docker-compose.yml)），各处必须保持一致。

```bash
docker compose up -d --build   # 改了 Dockerfile 或依赖后重建
docker compose restart         # 改了 .env / 代码后重启
docker compose stop            # 停止容器（保留容器，用 start 原样起回来）
docker compose start           # 起回被 stop 的容器
docker compose down            # 停止并删除容器和网络，只在确实要清理时用
```

- **「停掉服务」必须用 `docker compose stop`**，必须严格按用户说的范围执行。`down` 会删除容器和网络（踩过：用户说「把服务停掉」，执行的是 `down`）。
- `down -v` 会连 volume 一起删，`-v` 只在用户明确要求时才加。

**Git 与文档**

- 仓库远程 `https://github.com/tankmask0924-ops/phoneBill`（private），跟 platform 是两个独立仓库。
- 新增权限编码时同步更新 `AdminBootstrapService::KNOWN_PERMISSIONS` 和 README 的接口一览。
