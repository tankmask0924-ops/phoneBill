# phoneBill 话费充值 · 商户接入文档

> 最后更新：2026-10-10

本文档面向接入话费充值的商户。接口地址里的 `{BASE}` 由平台提供，例如 `https://平台域名`。

## 目录

1. [接入准备](#1-接入准备)
2. [通用约定](#2-通用约定)
3. [接口](#3-接口)
4. [结果通知](#4-结果通知)
5. [对接建议](#5-对接建议)
6. [常见问题](#6-常见问题)

## 1. 接入准备

平台运营会提供以下信息：

| 项目 | 说明 |
|---|---|
| AppKey | 32 位，标识你的商户 |
| AppSecret | 64 位签名密钥，**只发一次，请妥善保存**；泄露了请联系平台重置 |
| IP 白名单 | 把调用接口的服务器出口 IP 报给平台；配置了白名单后，不在名单里的请求会被拒绝 |
| 通知地址 | 订单出结果后平台推送到这里，也可以每次下单时单独传 |
| 可下单商品 | 商品编码和你的价格，也可以用「查商品」接口获取 |
| 商户后台 | `{BASE}/merchant/`，查看订单、资金流水和余额，账号由平台开通 |

下单前请确认预存余额充足：**下单时立即扣款，充值失败会自动退回**。

## 2. 通用约定

### 2.1 请求

- 编码 UTF-8。
- GET 接口的参数放在 query string；POST 接口用 JSON 请求体，`Content-Type: application/json`。
- 参数值只能是字符串或数字，不能是对象或数组。
- 每个请求都要带以下公共参数：

| 参数 | 必填 | 说明 |
|---|---|---|
| `app_key` | 是 | AppKey |
| `timestamp` | 是 | 当前时间，秒级 Unix 时间戳（10 位数字），和平台时间相差不能超过 300 秒 |
| `nonce` | 是 | 随机字符串，最长 64 位，**每次请求都不能重复** |
| `sign` | 是 | 签名，见 2.2 |

### 2.2 签名

1. 取除 `sign` 以外、**值不为空**的全部参数（公共参数 + 业务参数）；
2. 按参数名的 ASCII 顺序升序排列，拼成 `k1=v1&k2=v2&...`（值保持原样，**不做 URL 编码**）；
3. 用 AppSecret 对这个字符串做 HMAC-SHA256，结果转成**小写十六进制**（64 位），就是 `sign`。

**示例**（AppSecret 为 `test-secret`）：

```text
参数：
  app_key=ak_demo
  product_code=HF100
  mobile=13800138000
  merchant_order_no=M20261010001
  timestamp=1791600000
  nonce=8f3a1c2b9d4e5f60

待签名串：
app_key=ak_demo&merchant_order_no=M20261010001&mobile=13800138000&nonce=8f3a1c2b9d4e5f60&product_code=HF100&timestamp=1791600000

sign：
b02b405772c8a08b2c6f05cd1f66bf7c20214f6e5f94ea8607b59038edd6d2f2
```

可以用这组数据核对自己的签名实现。

**PHP 参考实现：**

```php
function sign(array $params, string $secret): string
{
    unset($params['sign']);
    $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
    ksort($params, SORT_STRING);
    $pairs = [];
    foreach ($params as $k => $v) {
        $pairs[] = "{$k}={$v}";
    }

    return hash_hmac('sha256', implode('&', $pairs), $secret);
}
```

**Java 参考实现：**

```java
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.nio.charset.StandardCharsets;
import java.util.Map;
import java.util.StringJoiner;
import java.util.TreeMap;

public static String sign(Map<String, Object> params, String secret) throws Exception {
    StringJoiner joiner = new StringJoiner("&");
    for (Map.Entry<String, Object> e : new TreeMap<>(params).entrySet()) {
        Object v = e.getValue();
        if (e.getKey().equals("sign") || v == null || v.toString().isEmpty()) {
            continue;
        }
        joiner.add(e.getKey() + "=" + v);
    }
    Mac mac = Mac.getInstance("HmacSHA256");
    mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
    StringBuilder hex = new StringBuilder();
    for (byte b : mac.doFinal(joiner.toString().getBytes(StandardCharsets.UTF_8))) {
        hex.append(String.format("%02x", b));
    }
    return hex.toString();
}
```

### 2.3 响应

接口正常处理时 HTTP 状态码是 200，用 `code` 判断结果：

```json
{"code": "OK", "message": "成功", "data": { ... }}
```

```json
{"code": "INSUFFICIENT_BALANCE", "message": "余额不足：当前 10.00 元，需要 99.50 元", "data": null}
```

| code | 含义 | 处理建议 |
|---|---|---|
| `OK` | 成功 | |
| `INVALID_PARAMS` | 参数缺失或格式不对 | 按 `message` 检查参数 |
| `INVALID_APP_KEY` | AppKey 不存在 | 核对 AppKey |
| `MERCHANT_DISABLED` | 商户已停用 | 联系平台 |
| `IP_NOT_ALLOWED` | 请求 IP 不在白名单 | 把 `message` 里的 IP 报给平台 |
| `INVALID_TIMESTAMP` | 时间戳格式不对或已过期 | 用秒级时间戳，校准服务器时间 |
| `INVALID_SIGN` | 签名错误 | 对照 2.2 的示例检查 |
| `DUPLICATE_NONCE` | nonce 重复或超过 64 位 | 每次请求换一个 nonce |
| `PRODUCT_NOT_AVAILABLE` | 商品不存在、已下架或没有为你开通 | 用「查商品」确认商品编码 |
| `UNSUPPORTED_MOBILE` | 无法识别号码运营商，或是虚拟运营商号码 | 不支持该号码 |
| `PRICE_NOT_SET` | 该商品不支持这个号码的运营商 | 看「查商品」里的 `prices` |
| `NO_CHANNEL` | 该号码所在地区暂时无法充值 | 稍后再试 |
| `BLACKLISTED` | 号码暂不支持充值 | |
| `DUPLICATE_RECHARGE` | 该号码 10 分钟内有正在处理的同面值订单 | 等上一单出结果 |
| `INSUFFICIENT_BALANCE` | 余额不足 | 充值后再下单 |
| `ORDER_NOT_FOUND` | 订单不存在 | |

**以上错误都表示订单没有创建、没有扣款**，修正后可以用同一个商户订单号重新提交。

**HTTP 状态码不是 200、响应无法解析、网络超时**时，说明结果未知：下单接口请用**同一个商户订单号**重新提交或查单确认（见 3.1 幂等），不要当作失败。

## 3. 接口

| 接口 | 方法 | 路径 |
|---|---|---|
| 下单 | POST | `{BASE}/open/v1/recharge` |
| 查单 | GET | `{BASE}/open/v1/order` |
| 查余额 | GET | `{BASE}/open/v1/balance` |
| 查商品 | GET | `{BASE}/open/v1/products` |

### 3.1 下单

`POST {BASE}/open/v1/recharge`

| 参数 | 必填 | 说明 |
|---|---|---|
| `product_code` | 是 | 商品编码 |
| `mobile` | 是 | 11 位手机号 |
| `merchant_order_no` | 是 | 你的订单号，1~64 位字母、数字、`_` 或 `-`，**在你的商户内唯一** |
| `notify_url` | 否 | 本单的结果通知地址，`http://` 或 `https://` 开头，最长 500 位；不传用平台配置的 |

请求示例：

```bash
curl -X POST '{BASE}/open/v1/recharge' \
  -H 'Content-Type: application/json' \
  -d '{
    "app_key": "ak_demo",
    "product_code": "HF100",
    "mobile": "13800138000",
    "merchant_order_no": "M20261010001",
    "timestamp": "1791600000",
    "nonce": "8f3a1c2b9d4e5f60",
    "sign": "b02b405772c8a08b2c6f05cd1f66bf7c20214f6e5f94ea8607b59038edd6d2f2"
  }'
```

响应示例：

```json
{
    "code": "OK",
    "message": "成功",
    "data": {
        "order_no": "2026101012000038271946",
        "merchant_order_no": "M20261010001",
        "product_code": "HF100",
        "mobile": "13800138000",
        "operator": "cmcc",
        "face_value": 100,
        "amount": "99.50",
        "status": "processing",
        "fail_reason": null,
        "created_at": "2026-10-10 12:00:00",
        "finished_at": null
    }
}
```

`data` 为订单信息（见 3.5），下单成功时 `status` 为 `processing`，最终结果以通知或查单为准。

**幂等：**

- 同一个 `merchant_order_no` 重复提交，直接返回第一次创建的订单（其他参数不同也一样），不会重复扣款。
- 网络超时拿不到响应时，用同一个订单号重新提交，或者用 `merchant_order_no` 查单即可。查单返回 `ORDER_NOT_FOUND` 说明订单没建成，可以放心用同一个订单号重新提交。
- 订单失败后要重新充值，请使用**新的**订单号（旧订单号会一直返回那笔失败的订单）。

### 3.2 查单

`GET {BASE}/open/v1/order`

| 参数 | 必填 | 说明 |
|---|---|---|
| `order_no` | 二选一 | 平台订单号 |
| `merchant_order_no` | 二选一 | 你的订单号 |

请求示例（参数和签名都放在 query string 里）：

```text
GET {BASE}/open/v1/order?app_key=ak_demo&merchant_order_no=M20261010001&timestamp=1791600300&nonce=0c1d2e3f4a5b6c7d&sign=2ece2bbb93a8dda251c0902b0620578059b7aedeabdeff801c987d1fff00603f
```

返回 `data` 为订单信息（见 3.5）。只能查到自己的订单。

### 3.3 查余额

`GET {BASE}/open/v1/balance`，只需要公共参数。

```json
{"code": "OK", "message": "成功", "data": {"balance": "1000.00"}}
```

### 3.4 查商品

`GET {BASE}/open/v1/products`，只需要公共参数。返回为你开通的商品，`prices` 是各运营商的价格，**`prices` 里没有的运营商不能下单**：

```json
{
    "code": "OK",
    "message": "成功",
    "data": [
        {"product_code": "HF100", "name": "话费100", "face_value": 100, "prices": {"cmcc": "99.50", "ctcc": "99.80"}}
    ]
}
```

运营商编码：`cmcc` 移动、`cucc` 联通、`ctcc` 电信、`cbn` 广电。

### 3.5 订单信息

| 字段 | 类型 | 说明 |
|---|---|---|
| `order_no` | string | 平台订单号 |
| `merchant_order_no` | string | 你的订单号 |
| `product_code` | string | 商品编码 |
| `mobile` | string | 手机号 |
| `operator` | string | 运营商：`cmcc` / `cucc` / `ctcc` / `cbn` |
| `face_value` | int | 面值（元） |
| `amount` | string | 扣款金额（元，两位小数） |
| `status` | string | `processing` 充值中 / `success` 成功 / `failed` 失败（已退款） |
| `fail_reason` | string \| null | 失败原因，仅失败时有值 |
| `created_at` | string | 下单时间，`yyyy-MM-dd HH:mm:ss` |
| `finished_at` | string \| null | 完成时间，充值中为 null |

## 4. 结果通知

订单成功或失败后，平台向通知地址 `POST` JSON（`Content-Type: application/json`），内容是订单信息（3.5）加上 `app_key`、`timestamp`、`nonce`、`sign`：

```json
{
    "order_no": "2026101012000038271946",
    "merchant_order_no": "M20261010001",
    "product_code": "HF100",
    "mobile": "13800138000",
    "operator": "cmcc",
    "face_value": 100,
    "amount": "99.50",
    "status": "success",
    "fail_reason": null,
    "created_at": "2026-10-10 12:00:00",
    "finished_at": "2026-10-10 12:01:30",
    "app_key": "ak_demo",
    "timestamp": 1791600090,
    "nonce": "a1b2c3d4e5f60718",
    "sign": "e185196b4b275e6e9bdc10cc9c82f2ef6b4ca725b05052293fd0d202bd7900b1"
}
```

**请先验签再处理**，签名方法同 2.2：值为 `null` 或空的字段（如上例的 `fail_reason`）不参与签名，数字按原样转成字符串（`face_value=100`）。上例的待签名串：

```text
amount=99.50&app_key=ak_demo&created_at=2026-10-10 12:00:00&face_value=100&finished_at=2026-10-10 12:01:30&merchant_order_no=M20261010001&mobile=13800138000&nonce=a1b2c3d4e5f60718&operator=cmcc&order_no=2026101012000038271946&product_code=HF100&status=success&timestamp=1791600090
```

用 AppSecret `test-secret` 算出来就是上面的 `sign`。

**应答和重试：**

- 收到并处理后，请在 **5 秒内**返回 HTTP 200，响应体为 `success`（不区分大小写）。
- 否则平台会在 1、5、15、30、60 分钟后重试，加上第一次共通知 6 次。
- 同一订单可能收到多次通知，请按 `order_no` 做幂等处理，以通知里的最新状态为准。
- 极少数情况下（供应商事后撤销），**成功的订单会被改为失败并退款**，平台会再通知一次 `failed`。

## 5. 对接建议

1. 下单返回 `processing` 后，以结果通知为主、查单为辅（例如 10 分钟没收到通知再查）。
2. 话费充值通常几秒到几分钟出结果；个别订单需要人工核实，最长可能几小时。
3. **不要把 `processing` 当失败处理并重新下单**，否则可能重复充值。
4. 下单超时、HTTP 非 200 时，用同一个 `merchant_order_no` 重试或查单，不要换新订单号。
5. 通知接口先返回 `success` 再异步处理业务，避免处理慢超过 5 秒被判为失败。

## 6. 常见问题

**签名一直报 `INVALID_SIGN`？**
先用 2.2 的示例数据核对实现。常见原因：空值参数参与了签名、对值做了 URL 编码、参数名没按 ASCII 排序、结果用了大写或 Base64、签名用的参数和实际发送的不一致。

**报 `INVALID_TIMESTAMP`？**
`timestamp` 必须是 10 位秒级时间戳（不是毫秒），并且服务器时间要准，和平台相差不超过 300 秒。

**报 `IP_NOT_ALLOWED`？**
`message` 里会写平台看到的请求 IP，把它报给平台加进白名单。

**同一个号码连续充两笔同面值的会怎样？**
上一笔还在处理中时（10 分钟内），第二笔会返回 `DUPLICATE_RECHARGE`、不扣款。等第一笔出结果后再下单。
