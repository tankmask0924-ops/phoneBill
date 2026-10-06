# phoneBill 话费充值 · 商户接入文档

> 最后更新：2026-10-06。接口地址里的 `{BASE}` 由平台提供，例如 `https://api.example.com/api`。

## 1. 接入准备

平台运营会提供以下信息：

| 项目 | 说明 |
|---|---|
| AppKey | 32 位，标识你的商户 |
| AppSecret | 64 位签名密钥，**只发一次，请妥善保存**；泄露了请联系平台重置 |
| IP 白名单 | 把调用接口的服务器出口 IP 报给平台；不在白名单里的请求会被拒绝 |
| 通知地址 | 订单出结果后平台推送到这里，也可以每次下单时单独传 |
| 可下单商品 | 商品编码和你的价格，也可以用「查商品」接口获取 |

下单前请确认预存余额充足：下单时立即扣款，充值失败会自动退回。

## 2. 通用约定

### 2.1 请求

- 编码 UTF-8。GET 接口参数放在 query string；POST 接口用 JSON：`Content-Type: application/json`。
- 每个请求都要带以下公共参数：

| 参数 | 必填 | 说明 |
|---|---|---|
| `app_key` | 是 | AppKey |
| `timestamp` | 是 | 当前时间，秒级 Unix 时间戳，和平台时间相差不能超过 300 秒 |
| `nonce` | 是 | 随机字符串，最长 64 位，**每次请求都不能重复** |
| `sign` | 是 | 签名，见 2.2 |

### 2.2 签名

1. 取除 `sign` 以外、值不为空的全部参数（公共参数 + 业务参数）；
2. 按参数名的 ASCII 顺序升序排列，拼成 `k1=v1&k2=v2&...`（值不做 URL 编码）；
3. 用 AppSecret 对这个字符串做 HMAC-SHA256，结果转成**小写十六进制**，就是 `sign`。

示例（AppSecret 为 `test-secret`）：

```text
待签名串：app_key=abc&merchant_order_no=M001&mobile=13800138000&nonce=x1y2&product_code=HF100&timestamp=1791200000
sign = hex(hmac_sha256(待签名串, "test-secret"))
```

PHP 参考实现：

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

### 2.3 响应

HTTP 状态码统一是 200，用 `code` 判断结果：

```json
{"code": "OK", "message": "成功", "data": { ... }}
{"code": "INSUFFICIENT_BALANCE", "message": "余额不足：当前 10.00 元，需要 99.50 元", "data": null}
```

| code | 含义 | 处理建议 |
|---|---|---|
| `OK` | 成功 | |
| `INVALID_PARAMS` | 参数缺失或格式不对 | 检查参数 |
| `INVALID_APP_KEY` | AppKey 不存在 | |
| `MERCHANT_DISABLED` | 商户已停用 | 联系平台 |
| `IP_NOT_ALLOWED` | 请求 IP 不在白名单 | 把出口 IP 报给平台 |
| `INVALID_TIMESTAMP` | 时间戳过期 | 校准服务器时间 |
| `INVALID_SIGN` | 签名错误 | 对照 2.2 检查 |
| `DUPLICATE_NONCE` | nonce 重复 | 每次请求换一个 nonce |
| `PRODUCT_NOT_AVAILABLE` | 商品不存在、已下架或没有为你开通 | |
| `UNSUPPORTED_MOBILE` | 无法识别号码运营商，或是虚拟运营商号码 | 不支持该号码 |
| `PRICE_NOT_SET` | 该商品不支持这个号码的运营商 | |
| `NO_CHANNEL` | 该号码所在地区暂时无法充值 | 稍后再试 |
| `BLACKLISTED` | 号码暂不支持充值 | |
| `DUPLICATE_RECHARGE` | 该号码 10 分钟内有正在处理的同面值订单 | 等上一单出结果 |
| `INSUFFICIENT_BALANCE` | 余额不足 | 充值后再下单 |
| `ORDER_NOT_FOUND` | 订单不存在 | |

**以上错误都表示订单没有创建、没有扣款**，可以修正后用同一个商户订单号重新提交。

## 3. 接口

### 3.1 下单

`POST {BASE}/open/v1/recharge`

| 参数 | 必填 | 说明 |
|---|---|---|
| `product_code` | 是 | 商品编码 |
| `mobile` | 是 | 11 位手机号 |
| `merchant_order_no` | 是 | 你的订单号，1~64 位字母、数字、`_` 或 `-`，**在你的商户内唯一** |
| `notify_url` | 否 | 本单的结果通知地址，不传用平台配置的 |

返回 `data` 为订单信息（见 3.5），下单成功时 `status` 为 `processing`。

**幂等：** 同一个 `merchant_order_no` 重复提交，返回第一次创建的订单，不会重复扣款。网络超时拿不到响应时，用同一个订单号重新提交或查单即可。订单失败后要重新充值，请使用新的订单号。

### 3.2 查单

`GET {BASE}/open/v1/order`

| 参数 | 必填 | 说明 |
|---|---|---|
| `order_no` | 二选一 | 平台订单号 |
| `merchant_order_no` | 二选一 | 你的订单号 |

返回 `data` 为订单信息（见 3.5）。

### 3.3 查余额

`GET {BASE}/open/v1/balance`，无业务参数。返回 `{"balance": "1000.00"}`。

### 3.4 查商品

`GET {BASE}/open/v1/products`，无业务参数。返回为你开通的商品，`prices` 是各运营商的价格（`cmcc` 移动、`cucc` 联通、`ctcc` 电信、`cbn` 广电），没有的运营商不能下单：

```json
[{"product_code": "HF100", "name": "话费100", "face_value": 100, "prices": {"cmcc": "99.50", "ctcc": "99.80"}}]
```

### 3.5 订单信息

| 字段 | 说明 |
|---|---|
| `order_no` | 平台订单号 |
| `merchant_order_no` | 你的订单号 |
| `product_code` | 商品编码 |
| `mobile` | 手机号 |
| `operator` | 运营商：`cmcc` / `cucc` / `ctcc` / `cbn` |
| `face_value` | 面值（元） |
| `amount` | 扣款金额（元） |
| `status` | `processing` 充值中 / `success` 成功 / `failed` 失败（已退款） |
| `fail_reason` | 失败原因，仅失败时有值 |
| `created_at` | 下单时间 |
| `finished_at` | 完成时间，充值中为 null |

## 4. 结果通知

订单成功或失败后，平台向通知地址 `POST` JSON，内容是订单信息（3.5）加上 `app_key`、`timestamp`、`nonce`、`sign`，签名方法同 2.2，**请先验签再处理**。

- 收到并处理后，请返回 HTTP 200，响应体为 `success`（不区分大小写）。
- 否则平台会在 1、5、15、30、60 分钟后重试，共通知 6 次。
- 同一订单可能收到多次通知，请按 `order_no` 做幂等处理，以通知里的最新状态为准。
- 极少数情况下（供应商事后撤销），成功的订单会被改为失败并退款，平台会再通知一次 `failed`。

## 5. 对接建议

1. 下单返回 `processing` 后，以结果通知为主、查单为辅（例如 10 分钟没收到通知再查）。
2. 话费充值通常几秒到几分钟出结果；个别订单可能需要人工核实，最长可能几小时。
3. 不要把 `processing` 当失败处理并重新下单，否则可能重复充值。
