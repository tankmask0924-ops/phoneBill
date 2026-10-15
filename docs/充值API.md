# 充值API接口文档（刘浩提供）

## 充值API

请求地址：http://ip端口/flow/charge.aspx（ip端口信息 见文档最底部）

请求方式：get

### 请求参数

| 参数名 | 说明 |
| --- | --- |
| method | 业务名称（charge） |
| user | 代理商账号 |
| custom_number | 客户方订单号，需要唯一 |
| mobile | 需要充值流量的11位手机号码 |
| product | 流量产品传入100M,1G，5G7D(5G7天包)<br>话费产品传入100,50,30,10 |
| network | (可选，不参与签名)运营商 1移动，2联通，3电信 |
| area | (可选，不参与签名)归属地省份名称 如：山东、陕西、河北......不需要带最后的省字 |
| sign | 明文：method=charge&user=账号&custom_number=订单号&mobile=手机号&product=流量包<br>秘钥：key<br>sign=MD5(明文+秘钥)<br>Md5签名结果为32位小写格式 |

### 响应结果

| 参数名 | 说明 |
| --- | --- |
| ResultCode | 0000 受理成功 (除了0000 下面响应码按失败处理)<br>9522 手机号码有误<br>9500 账号信息未找到<br>9511 签名验证未通过<br>9533 请求的流量包未找到<br>9555 余额不足<br>1111 其他原因<br>9545 订单号重复 |
| ResultMessage | 消息描述 |

### 示例

```
key=AAAAAA
签名=Md5（method=charge&user=test&custom_number=8903849043&mobile=15566667777&product=100MAAAAAA）

请求：http://IP:端口/flow/charge.aspx?method=charge&user=test&custom_number=8903849043&mobile=15566667777&product=100M&sign=签名

响应结果
{"ResultCode":"0000","ResultMessage":"受理成功"}
```

## 异步回调API

请求地址：代理商提供回调地址 url

回调方式：post

### 请求参数

| 参数名 | 说明 |
| --- | --- |
| Custom_Number | 代理商订单号 |
| Mobile | 订单充值的号码 |
| OrderStatus | 2000 充值成功<br>3000 充值失败 |
| ResultMessage | 描述信息 |
| Sign | 签名=MD5(Mobile+Custom_Number+key)<br>Key秘钥<br>Md5签名结果为32位小写格式 |
| Voucher | 充值凭证流水号 |

### 示例

```
key=AAAAAA
回调内容：
{"Custom_Number":"8902027384","Mobile":"15498785038","OrderStatus":"2000","ResultMessage":"充值成功","Sign":"b254630f18346917b989f772c0a7fda8","Voucher":""}

处理完毕响应内容：ok
```

## 查询订单状态API

请求地址：http://ip端口/flow/charge.aspx（ip端口信息 见文档最底部）

请求方式：get

### 请求参数

| 参数名 | 说明 |
| --- | --- |
| method | 业务名称（orderstatus） |
| user | 代理商账号 |
| custom_number | 代理方订单号 |
| sign | 明文：method=orderstatus&user=账号&custom_number=订单号<br>秘钥：key<br>sign=MD5(明文+秘钥)<br>Md5签名结果为32位小写格式 |

### 响应结果

| 参数名 | 说明 |
| --- | --- |
| ResultCode | 1111 调用验证失败（不可处理失败）<br>0000 订单正常 （此状态时可根据OrderStatus判断充值结果）<br>2222 订单不存在（可处理失败） |
| ResultMessage | 消息描述 |
| OrderStatus | 1000 充值中<br>2000 充值成功<br>3000 充值失败 |
| Voucher | 充值凭证流水号 |

### 示例

```
key=AAAAAA
签名=Md5（method=orderstatus&user=test&custom_number=8903849043AAAAAA）

请求：http://118.25.11.93:8089/flow/charge.aspx?method=orderstatus&user=test&custom_number=8903849043&sign=签名

响应结果
{"ResultCode":"0000","ResultMessage":"充值成功","OrderStatus":"2000","Voucher":""}
(0000代表查询成功，红色的2000代表订单充值成功)
```

## 余额查询API

请求地址：http://ip端口/flow/charge.aspx（ip端口信息 见文档最底部）

请求方式：get

### 请求参数

| 参数名 | 说明 |
| --- | --- |
| method | 业务名称（balance） |
| user | 代理商账号 |
| sign | 明文：method=balance&user=账号<br>秘钥：key<br>sign=MD5(明文+秘钥)<br>Md5签名结果为32位小写格式 |

### 响应结果

| 参数名 | 说明 |
| --- | --- |
| ResultCode | 0000 查询成功<br>1111 查询失败 |
| ResultMessage | 消息描述 |
| Balance | 余额 |

### 示例

```
key=AAAAAA
签名=Md5（method=balance&user=testAAAAAA）

请求：http://IP:端口/flow/charge.aspx?method=balance&user=test&sign=签名

响应结果
{"ResultCode":"0000","ResultMessage":"查询成功","Balance":"888.88"}
```

## 我们的对接方式（驱动 `liuhao`）

- 接口参数：接口地址（`http://IP:端口`，路径 `/flow/charge.aspx` 写在驱动里）、代理商账号（`user`）、秘钥。
- **回调地址不随请求传**：要把后台供应商列表里显示的回调地址（`回调域名/notify/supplier/{供应商编码}`）发给上游，让上游配上。
- 只接话费。字段对应：`custom_number` = 提交单号 `attempt_no`，`product` = 供应商商品编码（话费就是面值，建供应商商品时编码填 `100`、`50` 这种），`mobile` = 手机号；另外带上不参与签名的 `network`（移动 1、联通 2、电信 3，广电不传）和 `area`（省份，不带「省」字）。上游不返回自己的订单号，供应商订单号留空。
- 签名：按文档明文的固定顺序拼 `k=v&...`（不排序），末尾直接拼秘钥，md5 小写；请求签名和回调签名都已用文档示例核对。
- 回调签名只有 `MD5(Mobile + Custom_Number + key)`，不含充值状态，同一笔单的签名是固定的，截获一次就能改状态重放。所以回调验签通过后，驱动再主动查一次单，**以查单结果为准**；查单出错或还在充值中就保持处理中，交给定时查单。验签通过回 `ok`，不通过回 `fail`。

| 情况 | 结果 |
|---|---|
| 下单 `0000` | 处理中，等回调 / 查单 |
| 下单 `0000` 以外的响应码（`9522`、`9500`、`9511`、`9533`、`9555`、`1111`、`9545` 和表外的码） | 失败，换下一个供应商（文档：除了 0000 都按失败处理，2026-10-15 定；`9545` 订单号重复也判失败） |
| 下单网络超时、连不上、HTTP 出错、返回无法解析（没有拿到返回值） | 立即转异常订单并标为人工处理单：上游结果只记录，状态和回调全部人工（见 docs/project.md 2.5） |
| 查单 `0000` + `OrderStatus=2000` | 成功 |
| 查单 `0000` + `OrderStatus=3000` | 失败，原因取 `ResultMessage` |
| 查单 `0000` + `OrderStatus=1000` | 处理中 |
| 查单 `2222` 订单不存在 | 失败（文档：可处理失败） |
| 查单 `1111` 调用验证失败、没有 `OrderStatus`、返回无法解析 | 处理中，到时转异常人工处理 |
| 回调验签通过 | 回 `ok`，再查一次单，按上面查单的规则处理 |
| 回调验签不通过 | 不采用，回 `fail` |

## 待和上游确认

1. 回调是 JSON 还是表单（驱动两种都认）；没收到 `ok` 时会不会重推、推几次。
2. 回调签名能否把 `OrderStatus` 加进去（加了之后可以直接采用回调结果，不用再查单）。
3. 接口地址是 `http`，能否提供 `https`。
