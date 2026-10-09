# 中控 API 接口文档（整理版）

上游供应商「中控」的话费 / 卡密接口。根据对方提供的原始文档整理：统一了格式，修正了示例里明显的笔误（字段名前多出的空格、中文引号、`num` 示例填成了回调地址等）。原文没说清的地方集中列在最后的 [待确认事项](#待确认事项)，对接前需要和对方确认。

## 1. 通用约定

| 项目 | 说明 |
|---|---|
| 请求方式 | 全部 `POST` |
| Content-Type | `application/json` |
| 数据类型 | 参数值一律用字符串传输 |
| 接口地址 | 原文没给域名和各接口路径（见待确认 1） |
| 商家标识 | 每个请求都带 `appid`（平台分配的商家ID） |
| 时间戳 | `timestamp`，格式 `yyyyMMddHHmmss`，如 `20170928115522`（原文示例里写的是 10 位数字，见待确认 2） |

### 签名

1. `sign` 不参与签名；
2. 其余参数按参数名（key）字母升序排列，拼成 `key1=value1&key2=value2`；
3. 末尾拼上 `&key=秘钥`；
4. 整串做 MD5（原文示例是 32 位小写）。

```
sign = md5("key1=value1&key2=value2&key=秘钥")
```

PHP 参考实现：

```php
function sign(array $params, string $secret): string
{
    unset($params['sign']);
    ksort($params, SORT_STRING);
    $pairs = [];
    foreach ($params as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    $pairs[] = 'key=' . $secret;

    return md5(implode('&', $pairs));
}
```

空值参数是否参与签名，原文只在回调里说了 `param1`、`param2` 为空时不参与（见待确认 3）。

## 2. 充值下单

**请求参数**

| 参数 | 名称 | 参与签名 | 必填 | 说明 |
|---|---|---|---|---|
| `inOrderNumber` | 商家订单号 | 是 | 是 | 必须唯一 |
| `appid` | 商家ID | 是 | 是 | |
| `agentProductId` | 产品ID | 是 | 是 | 中控的商品编号 |
| `phone` | 充值号码 | 是 | 是 | 手机号；卡密商品填 `kami` |
| `notifyUrl` | 回调通知地址 | 是 | 是 | |
| `num` | 件数 | 是 | 是 | 话费固定填 `1`，卡密按实际数量 |
| `timestamp` | 请求时间戳 | 是 | 是 | `yyyyMMddHHmmss` |
| `sign` | 签名 | 否 | 是 | |

```json
{
    "inOrderNumber": "P2026100900000101",
    "appid": "4028b8815f6e3524015f6e3b61ef0004",
    "agentProductId": "4028b8815f7d4ae2015f86ee6aa70009",
    "phone": "18000001111",
    "notifyUrl": "https://我方域名/notify/supplier/供应商编码",
    "num": "1",
    "timestamp": "20261009120000",
    "sign": "3183496d7f0cb397b819bdc89a11c0fc"
}
```

**响应**

```json
{ "code": "0", "msg": "success" }
```

| code | 说明 |
|---|---|
| `0` | 订单提交成功 |
| `1` | 商家订单号已经存在 |
| `500` | 提交失败，具体原因见 `msg` |

下单响应里没有中控的订单号。

## 3. 订单查询

**请求参数**

| 参数 | 名称 | 参与签名 | 必填 | 说明 |
|---|---|---|---|---|
| `inOrderNumber` | 商家订单号 | 是 | 是 | |
| `appid` | 商家ID | 是 | 是 | |
| `timestamp` | 请求时间戳 | 是 | 是 | `yyyyMMddHHmmss` |
| `sign` | 签名 | 否 | 是 | |

```json
{
    "appid": "4028b8815f6e3524015f6e3b61ef0004",
    "inOrderNumber": "P2026100900000101",
    "timestamp": "20261009120500",
    "sign": "3183496d7f0cb397b819bdc89a11c0fc"
}
```

**响应**

```json
{
    "code": "0",
    "msg": "success",
    "status": "3",
    "cards": "[{\"expiryDate\":\"2028-03-25\",\"password\":\"304739\",\"qrCodeUrl\":\"\",\"cardNumber\":\"2326992090666999865\"}]"
}
```

| 字段 | 类型 | 说明 |
|---|---|---|
| `code` | String | `0` 查询成功，`500` 错误 |
| `msg` | String | 错误信息 |
| `status` | Int | `3` 充值成功，`4` 充值失败，其他值都是充值中 |
| `cards` | String | 卡密商品成功时的卡密信息（JSON 字符串），话费不用 |

## 4. 余额查询

**请求参数**

| 参数 | 名称 | 必填 | 说明 |
|---|---|---|---|
| `appid` | 商家ID | 是 | |
| `timestamp` | 时间戳 | 是 | `yyyyMMddHHmmss` |
| `sign` | 签名 | 是 | |

```json
{ "appid": "xxxx", "timestamp": "20261009120000", "sign": "xxxx" }
```

**响应**

```json
{
    "amount": "0",
    "qualification": "0",
    "freezeAmount": "0",
    "code": "0",
    "message": "操作成功"
}
```

| 字段 | 类型 | 说明 |
|---|---|---|
| `code` | String | `0` 查询成功，`500` 错误 |
| `msg` / `message` | String | 错误信息（说明里写 `msg`，示例里是 `message`） |
| `debtAmount` | decimal | 商家余额（元）；原文示例里没有这个字段 |
| `amount` | decimal | 已消费金额（元） |
| `freezeAmount` | decimal | 冻结金额（元） |
| `qualification` | decimal | 授信金额（元） |

## 5. 结果回调

由中控 POST 到下单时传的 `notifyUrl`，JSON 格式：

```json
{
    "phone": "15674874754",
    "status": 3,
    "thirdOrderId": "P2026100900000101",
    "param1": "",
    "param2": "",
    "cards": "",
    "sign": "c082d88cfbe03e0a52dc8f85439863f8"
}
```

| 字段 | 类型 | 说明 |
|---|---|---|
| `status` | int | `3` 充值成功，`4` 充值失败（原文示例里写的是 `2`，见待确认 5） |
| `thirdOrderId` | string | 商家订单号（即下单时的 `inOrderNumber`） |
| `phone` | string | 手机号码 |
| `param1` | string | 携号转网信息，未转网时为空；为空时不参与签名 |
| `param2` | string | 穿透流水号，可能为空；为空时不参与签名 |
| `cards` | string | 卡密信息 |
| `sign` | string | 签名，算法同上 |

**应答**：收到后返回纯文本 `success` 表示接收成功，返回其他内容视为失败（对方会重推，重推策略原文没写）。

## 6. 可售商品查询

**请求参数**

| 参数 | 名称 | 必填 | 说明 |
|---|---|---|---|
| `appid` | 商家ID | 是 | |
| `timestamp` | 时间戳 | 是 | `yyyyMMddHHmmss` |
| `sign` | 签名 | 是 | |

**响应**

```json
{
    "code": "0",
    "msg": "success",
    "products": [
        { "id": "0", "price": "0", "productName": "0", "isOnSale": "0" }
    ]
}
```

| 字段 | 类型 | 说明 |
|---|---|---|
| `code` | String | `0` 查询成功，`500` 错误 |
| `msg` | String | 错误信息 |
| `products[].id` | Int | 商品编号（下单时的 `agentProductId`） |
| `products[].price` | decimal | 销售价（元） |
| `products[].productName` | String | 商品名称 |
| `products[].isOnSale` | String | `0` 在售，`1` 下架 |

注意：下单示例里的 `agentProductId` 是 32 位字符串（`4028b8815f7d4ae2015f86ee6aa70009`），这里的商品 `id` 却标成 Int（见待确认 7）。

## 7. 我方驱动（`zhongkong`）的对接方式

| 驱动方法 | 中控接口 | 字段对应 |
|---|---|---|
| `recharge` | 充值下单 | `inOrderNumber` = 提交单号 `attempt_no`，`agentProductId` = 供应商商品编码，`phone` = 手机号，`num` = `1` |
| `query` | 订单查询 | 按 `inOrderNumber` 查 |
| `parseCallback` | 结果回调 | 用 `thirdOrderId` 找回提交；回调带签名，可以验签 |
| `balance` | 余额查询 | 取 `debtAmount`（待确认 6） |

后台需要填的接口参数：下单 / 查单 / 余额三个接口的完整地址（文档没给路径，所以分开填；余额不填就不查）、商家ID（`appid`）、秘钥、时间戳格式（年月日时分秒或秒级时间戳，文档前后矛盾，默认按参数表选年月日时分秒）、回调地址。商品查询接口驱动不用，只在建供应商商品时查 `agentProductId` 用。只接话费，`num` 固定 `1`，不接卡密。

结果判定（待确认事项没回复前按保守方式处理）：

| 情况 | 结果 |
|---|---|
| 下单 `code=0` | 处理中，等回调 / 查单 |
| 下单 `code=1` 订单号已存在 | **处理中**（说明之前已经提交过，绝不能当失败） |
| 下单 `code=500` 或其他 code | 处理中，等查单（确认 500 一定没建单后可改成直接失败） |
| 下单 HTTP 异常、超时、返回无法解析 | 处理中，等查单 |
| 查单 `status=3` | 成功 |
| 查单 `status=4` | 失败 |
| 查单其他 `status`、`code=500` | 处理中 |
| 回调验签通过，`status=3` / `4` / 其他 | 成功 / 失败 / 处理中，回 `success` |
| 回调验签不通过 | 不处理，回 `fail`（HTTP 400） |

回调验签：文档只说了 `param1`、`param2` 为空时不参与签名，没说其他空字段（比如话费的 `cards`）怎么算，所以「只去掉空的 param1 / param2」和「去掉所有空字段」两种算法有一种对上就通过；sign 不区分大小写。

## 待确认事项

1. **接口地址**：域名，以及下单、查单、余额、商品查询四个接口各自的路径。
2. **时间戳格式**：参数表写的是 `yyyyMMddHHmmss`（如 `20170928115522`），传入示例里却是 10 位数字（`1234577844`，像秒级时间戳）。以哪个为准？允许和对方服务器时间相差多少？
3. **空值参数**：请求里的空值参数是否参与签名？原文只在回调里说了 `param1`、`param2` 为空时不参与。签名用的值是否就是原始字符串（不做 URL 编码）？MD5 结果大写还是小写？
4. **下单 `code=500`**：是否一定没生成订单？我们要据此判断能不能直接判失败、给商户退款。
5. **回调状态码**：说明里是 `3` 成功、`4` 失败，示例里却是 `2`。会不会推 `3`、`4` 以外的状态？回调失败时的重推次数和间隔？
6. **余额字段**：可用余额取 `debtAmount` 吗？原文示例里没有这个字段，只有 `amount`（已消费）、`freezeAmount`、`qualification`。有授信的话，可下单额度怎么算？错误信息字段是 `msg` 还是 `message`？
7. **商品编号类型**：`agentProductId` 示例是 32 位字符串，商品查询里 `id` 标成 Int，以哪个为准？
8. **查单查不到**：订单不存在时返回什么（`code=500` 还是别的）？需要据此判断下单超时后对方到底有没有收到。
9. **重复提交**：同一个 `inOrderNumber` 再次提交会返回 `code=1`，原订单是否不受影响、继续正常处理？
10. **中控订单号**：下单、查单、回调都没有中控自己的订单号，对账时用我们的 `inOrderNumber` 就可以吗？
11. **回调来源**：回调是否有固定的出口 IP，可以加白名单？
