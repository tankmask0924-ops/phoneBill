Q Y L M · 卡站平台

# 开放接口对接文档

## 代理端下单 API · V1.0

**接口根地址：** http://120.26.33.144/api/open/v1

**鉴权方式：** 接口 ID + 接口 KEY（HMAC-SHA256 签名）

**数据格式：** JSON / UTF-8

**文档版本：** V1.0

2026 年 11 月

---

# 接入说明

## 基础信息

| 项目 | 说明 |
| --- | --- |
| 接口根地址 | http://120.26.33.144/api/open/v1 |
| 数据格式 | 请求体与响应体均为 JSON，字符集 UTF-8 |
| 鉴权方式 | 代理端「接口 ID + 接口 KEY」，HMAC-SHA256 签名（见第 01 章） |
| 时间容差 | 300 秒 |
| Nonce 有效期 | 600 秒，不可重复使用 |

## 统一响应结构

```json
{
  "code": 0,
  "msg": "ok",
  "data": {},
  "timestamp": 1762521600
}
```

- code = 0 表示业务成功，此时读取 data；
- code != 0 表示失败，msg 为失败原因，HTTP 状态码与 code 一致。

## 接入流程

1. 在代理端「账号 → 接口信息」获取 **接口 ID** 与 **接口 KEY**；
2. 按第 01 章「签名规则」生成 **X-Sign**；
3. 调用 **GET /api/open/v1/products** 获取商品 ID 与价格；
4. 调用 **POST /api/open/v1/orders** 提交订单；
5. 轮询 **GET /api/open/v1/orders/{order_no}** 获取最终结果。

## 接口目录

- **01 签名规则**：公共请求头、签名串与 PHP / Python 示例
- **02 商品列表**：GET /api/open/v1/products
- **03 商品详情**：GET /api/open/v1/products/{id}
- **04 订单提交**：POST /api/open/v1/orders
- **05 订单详情**：GET /api/open/v1/orders/{order_no}
- **06 账户余额**：GET /api/open/v1/account

# 01 签名规则

所有接口都必须携带以下请求头：

| 请求头 | 必填 | 说明 |
| --- | --- | --- |
| X-Agent-Id | 是 | 代理端账号 ID（纯数字） |
| X-Timestamp | 是 | 当前 Unix 时间戳，10 位秒级 |
| X-Nonce | 是 | 随机字符串，建议 16~32 位，10 分钟内不可重复 |
| X-Sign | 是 | HMAC-SHA256 签名，十六进制小写 |
| Content-Type | POST 必填 | application/json |

## 签名串

按以下 6 段用换行符 `\n` 拼接：

```text
agent_id
METHOD
URI
timestamp
nonce
raw_body
```

说明：

- agent_id：与 X-Agent-Id 完全一致
- METHOD：大写，如 GET、POST
- URI：请求路径，包含查询字符串。例如 /api/open/v1/products?page=1&size=20
- timestamp：与 X-Timestamp 完全一致
- nonce：与 X-Nonce 完全一致
- raw_body：原始请求体；GET 请求为空字符串，POST 请求为发送的 JSON 原文（不要重新格式化）

然后计算：

```text
X-Sign = hex_lower( HMAC-SHA256( key = 接口KEY, message = 签名串 ) )
```

## 校验规则

- 时间戳与服务器时间相差超过 **300 秒** 返回 401
- 同一 X-Nonce 在 **600 秒** 内重复使用返回 401
- 签名不匹配返回 401
- 代理账号停用、被删除或未配置 KEY 返回 401

## PHP 示例

```php
<?php
$agentId   = '1001';
$apiKey    = '你的接口KEY';
$method    = 'GET';
$uri       = '/api/open/v1/products?page=1&size=20';
$timestamp = (string)time();
$nonce     = bin2hex(random_bytes(12));
$body      = '';

$stringToSign = implode("\n", [$agentId, $method, $uri, $timestamp, $nonce, $body]);
$sign = hash_hmac('sha256', $stringToSign, $apiKey);

$ch = curl_init('http://120.26.33.144' . $uri);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'X-Agent-Id: ' . $agentId,
        'X-Timestamp: ' . $timestamp,
        'X-Nonce: ' . $nonce,
        'X-Sign: ' . $sign,
    ],
]);
$response = curl_exec($ch);
curl_close($ch);

echo $response;
```

## Python 示例

```python
import hashlib
import hmac
import time
import secrets
import requests

agent_id = "1001"
api_key = "你的接口KEY"
method = "GET"
uri = "/api/open/v1/products?page=1&size=20"
timestamp = str(int(time.time()))
nonce = secrets.token_hex(12)
body = ""

string_to_sign = "\n".join([agent_id, method, uri, timestamp, nonce, body])
sign = hmac.new(api_key.encode(), string_to_sign.encode(), hashlib.sha256).hexdigest()

resp = requests.get(
    "http://120.26.33.144" + uri,
    headers={
        "X-Agent-Id": agent_id,
        "X-Timestamp": timestamp,
        "X-Nonce": nonce,
        "X-Sign": sign,
    },
    timeout=10,
)
print(resp.text)
```

## 注意事项

1. 接口 KEY 只能保存在服务端，禁止下发到浏览器或 App；
2. 每个请求都要生成新的 nonce，不要缓存复用；
3. 请保持服务器时间与 NTP 同步；
4. 签名使用小写十六进制，服务端同样按小写比对；
5. 查询参数顺序必须与实际发送的 URL 完全一致。

# 02 商品列表

返回当前代理账号可购买的商品，价格已按「代理价 > 等级价 > 商品默认售价」计算。

- 接口：GET /api/open/v1/products
- 鉴权：见第 01 章「签名规则」

## 请求参数（Query）

| 参数 | 类型 | 必填 | 默认 | 说明 |
| --- | --- | --- | --- | --- |
| page | int | 否 | 1 | 页码 |
| size | int | 否 | 20 | 每页数量，1~100 |
| keyword | string | 否 | - | 商品名称模糊搜索 |
| category_id | int | 否 | 0 | 分类 ID，包含全部下级分类 |

请求示例：

```http
GET /api/open/v1/products?page=1&size=20&keyword=话费
```

## 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "items": [
      {
        "id": 12,
        "name": "全国话费充值 10 元",
        "image": "https://example.com/uploads/p12.png",
        "category_id": 3,
        "category_name": "话费充值",
        "price": "9.90",
        "product_type": "recharge",
        "order_template": "手机号码",
        "purchase_limit": 0,
        "sales": 128,
        "stock": 9999,
        "avg_duration": 15
      }
    ],
    "total": 1,
    "page": 1,
    "size": 20
  },
  "timestamp": 1762521600
}
```

## 字段说明

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 商品 ID，下单时使用 |
| name | string | 商品名称 |
| image | string | 商品图片 URL |
| category_id | int | 分类 ID |
| category_name | string | 分类名称 |
| price | string | 当前代理账号的实际购买价 |
| product_type | string | 商品类型 |
| order_template | string | 下单参数提示，如“手机号码” |
| purchase_limit | int | 限购数量，0 表示不限 |
| sales | int | 历史成功销量 |
| stock | int\|null | 可用库存；null 表示不限制 |
| avg_duration | int\|null | 平均处理耗时（秒） |

只返回已上架、且当前代理账号有权限购买的商品。无权限时 items 为空数组。

# 03 商品详情

- 接口：GET /api/open/v1/products/{id}
- 鉴权：见第 01 章「签名规则」

## 路径参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 商品 ID |

请求示例：

```http
GET /api/open/v1/products/12
```

## 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "id": 12,
    "name": "全国话费充值 10 元",
    "image": "https://example.com/uploads/p12.png",
    "category_id": 3,
    "category_name": "话费充值",
    "face_price": "10.00",
    "price": "9.90",
    "product_type": "recharge",
    "order_template": "手机号码",
    "purchase_limit": 0,
    "card_library_id": 0,
    "status": 1,
    "sales": 128,
    "stock": 9999,
    "avg_duration": 15
  },
  "timestamp": 1762521600
}
```

## 字段说明

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 商品 ID |
| name | string | 商品名称 |
| image | string | 商品图片 URL |
| category_id | int | 分类 ID |
| category_name | string | 分类名称 |
| face_price | string | 面值/原价 |
| price | string | 当前代理账号实际购买价 |
| product_type | string | 商品类型 |
| order_template | string | 下单参数提示 |
| purchase_limit | int | 限购数量，0 表示不限 |
| card_library_id | int | 绑定卡密库 ID，0 表示无 |
| status | int | 1 上架 |
| sales | int | 历史成功销量 |
| stock | int\|null | 可用库存；null 表示不限制 |
| avg_duration | int\|null | 平均处理耗时（秒） |

## 失败响应

```json
{
  "code": 404,
  "msg": "商品不存在或不可购买",
  "data": [],
  "timestamp": 1762521600
}
```

商品不存在、已下架、代理无权限或单独下架时返回 404。

# 04 订单提交

使用代理账号余额下单，下单成功后立即扣减余额并返回订单号。

- 接口：POST /api/open/v1/orders
- 鉴权：见第 01 章「签名规则」
- Content-Type：application/json

## 请求体

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| product_id | int | 是 | 商品 ID，来自商品列表 |
| params | string | 是 | 充值账号/下单参数，最长 255 字符 |
| trade_password | string | 条件必填 | 账号开启交易密码时必须填写 |

请求示例：

```json
{
  "product_id": 12,
  "params": "13800138000"
}
```

## 成功响应（HTTP 201）

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "order_no": "AP202611072043121A2B3C",
    "amount": "9.9000",
    "balance_after": "113.5500",
    "status": "pending",
    "status_label": "等待处理"
  },
  "timestamp": 1762521600
}
```

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| order_no | string | 订单号，查询订单时使用 |
| amount | string | 本次扣款金额 |
| balance_after | string | 扣款后余额 |
| status | string | 初始状态固定为 pending |
| status_label | string | 状态中文说明 |

## 失败响应

| HTTP | 场景 | 示例 msg |
| --- | --- | --- |
| 401 | 签名或账号异常 | 签名错误 |
| 404 | 商品不可购买 | 商品不存在或不可购买 |
| 422 | 参数缺失 | 请选择要购买的商品 |
| 422 | 参数为空 | 请填写充值账号或下单参数 |
| 422 | 参数过长 | 下单参数不能超过 255 个字符 |
| 422 | 需要交易密码 | 请输入交易密码 |
| 422 | 交易密码错误 | 交易密码不正确 |
| 422 | 余额不足 | 余额不足，请先充值 |

失败示例：

```json
{
  "code": 422,
  "msg": "余额不足，请先充值",
  "data": [],
  "timestamp": 1762521600
}
```

## 业务说明

- 下单即扣款，订单进入等待处理，并自动提交到对应货源渠道；
- 订单最终结果请通过 第 05 章「订单详情」 查询；
- 若商品为本地供货，订单会进入本地供货待处理队列，由代理端反向拉单完成充值；
- 订单失败时系统会自动把金额退回代理账号余额。

# 05 订单详情

查询当前代理账号自己的订单状态与处理结果。

- 接口：GET /api/open/v1/orders/{order_no}
- 鉴权：见第 01 章「签名规则」

## 路径参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| order_no | string | 是 | 下单返回的订单号，仅支持字母和数字 |

请求示例：

```http
GET /api/open/v1/orders/AP202611072043121A2B3C
```

## 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "order_no": "AP202611072043121A2B3C",
    "product_id": 12,
    "product_name": "全国话费充值 10 元",
    "product_image": "https://example.com/uploads/p12.png",
    "params": "13800138000",
    "amount": "9.90",
    "paid_amount": "9.90",
    "status": "success",
    "status_label": "充值成功",
    "message": "充值成功",
    "region_province": "",
    "region_city": "",
    "region_carrier": "",
    "created_at": "2026-11-07 20:43:12",
    "completed_at": "2026-11-07 20:43:40"
  },
  "timestamp": 1762521820
}
```

## 字段说明

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| order_no | string | 订单号 |
| product_id | int | 商品 ID |
| product_name | string | 商品名称 |
| product_image | string | 商品图片 |
| params | string | 下单参数 |
| amount | string | 订单金额 |
| paid_amount | string | 实付金额，未支付订单为 0.00 |
| status | string | 订单状态，见下表 |
| status_label | string | 状态中文说明 |
| message | string | 处理信息/失败原因 |
| region_province | string | 省份（可空） |
| region_city | string | 城市（可空） |
| region_carrier | string | 运营商（可空） |
| created_at | string | 下单时间 |
| completed_at | string | 完成时间，未完成时为空 |

## 订单状态

| status | 说明 | 是否终态 |
| --- | --- | --- |
| pending | 等待处理 | 否 |
| processing | 正在充值 | 否 |
| awaiting | 等待交付 | 否 |
| success | 充值成功 | 是 |
| failed | 充值失败 | 是 |
| cancelled | 已取消 | 是 |
| refunded | 已退款 | 是 |
| partial_refund | 部分退款 | 是 |
| unpaid | 取消交易 | 是 |

## 失败响应

订单不存在，或订单不属于当前代理账号：

```json
{
  "code": 404,
  "msg": "订单不存在",
  "data": [],
  "timestamp": 1762521820
}
```

## 建议轮询策略

- 下单后每 5~10 秒查询一次；
- 状态变为终态后停止轮询；

# 06 账户余额

查询当前代理账号的余额与消费统计。

- 接口：GET /api/open/v1/account
- 鉴权：见第 01 章「签名规则」

## 请求参数

无。

请求示例：

```http
GET /api/open/v1/account
```

## 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "agent_id": 1001,
    "name": "测试代理",
    "level": "VIP1",
    "balance": "123.4500",
    "frozen": "0.0000",
    "consumption": "100.0000",
    "today_consumption": "10.0000"
  },
  "timestamp": 1762521600
}
```

## 字段说明

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| agent_id | int | 代理账号 ID |
| name | string | 代理账号名称 |
| level | string | 用户等级 |
| balance | string | 可用余额 |
| frozen | string | 冻结金额 |
| consumption | string | 累计消费金额 |
| today_consumption | string | 今日消费金额 |

## 说明

- 余额单位：**元**，保留 4 位小数；
- 下单成功后余额实时扣减，可在下单响应 **balance_after** 中看到扣后余额；
- 订单失败退款后，余额会由系统自动加回；
- 本接口只返回当前签名代理账号的数据。

---

# 我们的对接方式（驱动 `qylm`）

- 接口参数：接口根地址（`http://120.26.33.144/api/open/v1`，驱动在后面拼 `/products`、`/orders`、`/orders/{order_no}`、`/account`）、接口 ID（代理账号 ID，`X-Agent-Id`）、接口 KEY；选填交易密码（上游账号开启交易密码时填，加密存储，随单发送但不记进原始报文）。
- 只接话费。字段对应：`product_id` = 供应商商品编码（上游商品 ID，可从商品列表里选；纯数字时按整数发），`params` = 手机号。上游返回的 `order_no` 存为供应商订单号。
- **上游没有接入方单号**：下单不带我们的提交单号，查单只能按上游 `order_no`。下单没拿到返回（或说成功却没给 `order_no`）的提交查不了单，这种单已经转人工处理单，要到上游后台核实；也不能拿同一笔再下一次单来「查」（会重复扣款）。
- **上游没有回调**：结果全靠定时查单；打到回调地址的请求一律回 `fail` 不采用。
- 鉴权放在请求头：`X-Agent-Id`、`X-Timestamp`（秒级）、`X-Nonce`（每次新生成 24 位十六进制）、`X-Sign`。签名串按第 01 章：`agent_id`、大写 METHOD、URI（请求路径含查询字符串，取实际请求地址）、timestamp、nonce、原始请求体（GET 为空串）用 `\n` 连接，接口 KEY 做 HMAC-SHA256，十六进制小写。POST 的请求体就是签名用的那段 JSON 原文。
- 余额取 `data.balance`（4 位小数，原样显示）。
- 商品列表每页 100 条逐页取完：`id` 作编码、`price` 作进价；列表不给面值，管理员自己填；没有运营商字段，商品名里带运营商名（移动 / 联通 / 电信 / 广电）时才认，否则留空；`stock=0` 算没货（`null` 不限库存）；分类名和下单参数提示作为补充说明。

| 情况 | 结果 |
|---|---|
| 下单 `code=0` 且带 `order_no`（HTTP 201） | 处理中，等查单 |
| 下单 `code` 不是 0（401 签名 / 账号、404 商品不可购买、422 参数 / 交易密码 / 余额不足、表外的码），不论 HTTP 状态码 | 失败，换下一个供应商（上游有返回、不是成功码就判失败） |
| 下单 `code=0` 但没有 `order_no` | 立即转异常订单并标为人工处理单（查不了单） |
| 下单网络超时、连不上、返回无法解析或没有 `code`（没有拿到返回值） | 立即转异常订单并标为人工处理单：上游结果只记录，状态全部人工（见 docs/project.md 2.5） |
| 查单 `status=success` | 成功 |
| 查单 `status=failed` / `cancelled` / `refunded` / `unpaid` | 失败（上游失败会自动退款给我们）；失败原因取 `message` |
| 查单 `status=partial_refund` 部分退款 | 处理中，不判结果，留给人工核实到账金额 |
| 查单 `status=pending` / `processing` / `awaiting` 或其他 | 处理中 |
| 查单 `code=404` 订单不存在 | 只记录，订单状态不动（单号是上游自己返回的，查不到不正常，不能判失败）；一直没结果的到时转异常人工处理 |
| 查单其他非 0 的 code、返回的 `order_no` 对不上、没有 `status`、没拿到返回 | 处理中，只记录；一直没结果的到时转异常人工处理 |
| 回调 | 上游没有回调，一律回 `fail` |

# 待和上游确认

1. 能否支持接入方单号（下单带我们的单号、按它查单、重复提交幂等），以便下单超时时能查到、不会重复下单。
2. 能否提供结果回调。
3. `refunded` 会不会出现在充值成功之后（成功后撤销）；现在按失败处理，成功后再查到的由订单流程按冲正规则人工处理。
4. 商品列表能否给出面值和运营商字段。
