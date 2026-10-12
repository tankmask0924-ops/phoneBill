# 大猿人充值系统 API 接口（整理版）

> 上游提供的原文档整理而来，接口、参数、示例保持原意；第 9 节是我们的对接方式和结果判定，第 10 节是待和上游确认的事项。
> 上游是通用充值系统（话费、电费、QQ 等），我们**只接话费**，电费相关参数不用。

## 1. 接口地址

- 请求地址前缀：`http://域名/yrapi.php/`，各接口路径见下文（如下单 `http://域名/yrapi.php/index/recharge`）。
- 提交方式：HTTP POST 表单，`Content-Type: application/x-www-form-urlencoded`。
- 响应：JSON。
- 充值结果回调：HTTP POST 表单，例如 `order_number=1&out_trade_num=1&mobile=1&otime=1&state=1`。

## 2. 签名

1. 取出全部待签名参数：所有「请求参数」或所有「回调参数」都要参与签名，**只去掉 `sign`**。每个接口参数不同，回调以后可能增加字段，要按实际收到的全部参数算，不能只算文档表格里的字段。
2. 参数名按字典升序排序，拼成 `a=1&b=2&c=3`，**不做 URL 编码**；`apikey` 不参与排序，直接拼在最后：`a=1&b=2&c=3&apikey=你的商户key`。
3. 对整串做 md5，转**大写**，就是 `sign`。

注意：

- 用 PHP `http_build_query` 拼串时会自动 URL 编码，要再 `urldecode` 一次；
- 提交的报文里不要带 apikey。

PHP 示例（请求签名）：

```php
$param = ['参数名称' => '参数值' /* ... */];
ksort($param);
$signStr = http_build_query($param) . '&apikey=aaaaaaaaaaaaaaaaaaa';
$param['sign'] = strtoupper(md5(urldecode($signStr)));
```

PHP 示例（回调验签）：

```php
$data = $_POST;
unset($data['sign']);
ksort($data);
$signStr = urldecode(http_build_query($data)) . '&apikey=' . $apikey;
if (strtoupper(md5($signStr)) == $_POST['sign']) {
    // 签名正确
}
```

## 3. 充值提交 `POST /yrapi.php/index/recharge`

| 参数 | 类型 | 必填 | 示例 | 说明 |
|---|---|---|---|---|
| out_trade_num | string | 是 | GG5822222266 | 商户订单号，商户自己生成、唯一；同一商户相同单号不能重复提单 |
| product_id | number | 是 | 68 | 产品 ID（代理后台查看） |
| mobile | string | 是 | 18866667777 | 充值号码（手机号、电费户号、QQ 号等） |
| notify_url | string | 是 | http://www.abc.com | 回调地址 |
| userid | string | 是 | 10001 | 商户 ID |
| amount | number | 否 | 100 | 面值，不传不校验；产品面值和它不同时驳回 |
| price | number | 否 | 94.8 | 最高成本，不传不校验；产品成本超过它时驳回 |
| area | string | 否 | 广东 | 电费省份，仅电费 |
| ytype | string | 否 | 1 | 电费验证三要素类型，仅南网电费 |
| id_card_no | string | 否 | 123456 | 身份证 / 银行卡 / 营业执照后 6 位，仅南网电费 |
| city | string | 否 | 广州 | 地级市，仅部分南网电费 |
| param1 ~ param3 | string | 否 | | 扩展参数，看产品类目是否需要 |
| sign | string | 是 | | 签名 |

请求示例：

```
out_trade_num=ABC1111&product_id=11&mobile=18899998888&notify_url=http://www.abc.com/yuanren&userid=10001&sign=GZWDK8X7TGJFA8N8O9HILQ6WSI46C8FJ
```

响应：

| 参数 | 类型 | 说明 |
|---|---|---|
| errno | string | 0 成功，非 0 提交失败 |
| errmsg | string | 错误描述 |
| data.order_number | string | 系统订单号 |
| data.mobile | string | 充值手机号 |
| data.product_id | string | 产品 ID |
| data.total_price | string | 消费金额 |
| data.out_trade_num | string | 商户订单号 |
| data.title | string | 充值产品说明 |

**HTTP 状态不是 200、或者响应体没有数据时，可能是服务器或链路故障，无法判断是否下单成功，要通过查单或人工确认。**

```json
{
    "errno": 0,
    "errmsg": "下单成功",
    "data": {
        "order_number": "XYZ111111",
        "mobile": "18866667777",
        "product_id": 10001,
        "total_price": "95.00",
        "out_trade_num": "ABC1111",
        "title": "100元话费"
    }
}
```

## 4. 查询订单 `POST /yrapi.php/index/check`

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| userid | string | 是 | 商户 ID |
| out_trade_nums | string | 是 | 商户订单号，多个用英文逗号分隔，如 `CZH668877,CZH9988666` |
| sign | string | 是 | 签名 |

响应 `data`（errno=0 时）：

| 参数 | 类型 | 示例 | 说明 |
|---|---|---|---|
| order_number | string | CZH1111111 | 系统订单号 |
| out_trade_num | string | AB882863666 | 商户订单号 |
| create_time | string | 1652403339 | 下单时间 |
| mobile | string | 18866667777 | 手机号 |
| product_id | string | 88 | 产品 ID |
| charge_amount | float | 100 | 充值成功面额 |
| charge_kami | string | | 卡密流水 |
| state | string | 1 | **-1 取消，0 充值中，1 充值成功，2 充值失败，3 部分成功** |

## 5. 充值结果回调（异步通知）

POST 表单，发到下单时传的 `notify_url`。收到后响应文本 `success`；不响应的话每隔 1 分钟重发，最多 5 次。

| 参数 | 类型 | 示例 | 说明 |
|---|---|---|---|
| userid | int | 10001 | 商户 ID |
| order_number | string | CZH000000000 | 系统订单号 |
| out_trade_num | string | ABC2222 | 商户订单号 |
| otime | number | 1652403339 | 成功 / 失败时间，10 位时间戳 |
| state | number | 1 | **-1 取消，0 充值中，1 充值成功，2 充值失败，3 部分成功**（-1、2 按失败处理，1 按成功处理，3 按部分成功处理） |
| mobile | string | 18866667777 | 充值手机号 |
| remark | string | 充值成功 | 备注 |
| charge_amount | float | 100 | 充值成功面额 |
| voucher | string | | 凭证 |
| charge_kami | string | | 卡密 / 流水号 |
| sign | string | | 签名；**所有收到的参数都参与验签**（以后可能增加参数） |

## 6. 查询账户 `POST /yrapi.php/index/user`

参数：`userid`、`sign`。响应 `data`：`id`（userid）、`username`、`balance`（余额）。

## 7. 产品

### 7.1 产品类型和分类 `POST /yrapi.php/index/typecate`

参数：`userid`、`sign`。响应 `data` 为列表：`id`（类型 ID）、`type_name`、`cate`（分类列表：`id`、`cate`、`type`）。

### 7.2 产品列表 `POST /yrapi.php/index/product`

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| userid | string | 是 | 商户 ID |
| type | int | 否 | 产品类型 ID |
| cate_id | int | 否 | 分类 ID |
| sign | string | 是 | 签名 |

响应 `data` 是分类列表，每个分类：`id`、`cate`（分类名）、`sort`、`type`、`products`（产品列表）。产品字段：

| 参数 | 说明 |
|---|---|
| id | 产品 ID，下单用 |
| name | 产品名称 |
| desc | 产品说明 |
| api_open | 是否开启自动充值 |
| isp | 运营商集合（话费、流量有效）：**1 移动、2 电信、3 联通、4 虚拟**（注意 2、3 的顺序） |
| ys_tag | 标签 |
| price | 价格，下单扣费金额 |
| y_price | 原价 |
| max_price | 封顶价格 |
| type / type_name | 产品类型 ID / 名称 |
| cate_name | 分类名称 |

## 8. 其他接口（我们不用）

- 电费支持地区 `POST /yrapi.php/index/elecity`：参数 `userid`、`sign`；返回地区列表（`city_name`、`sort`、`initial`、`need_ytype`、`need_city`、`city` 地级市列表）。
- 退单申请 `POST /yrapi.php/index/cancel`：参数 `userid`、`out_trade_nums`（逗号分隔）、`sign`。只是提交申请，上游处理完后通过回调推送结果。

## 9. 我们的对接方式（驱动 `dayuanren`）

- 接口参数：接口域名（`https://上游域名`，路径 `/yrapi.php/index/...` 写在驱动里）、商户ID（`userid`）、apikey；回调地址按系统配置的回调域名自动生成（`回调域名/notify/supplier/{供应商编码}`），每单随下单请求传过去。
- 字段对应：`out_trade_num` = 提交单号 `attempt_no`，`product_id` = 供应商商品编码，`mobile` = 手机号，`amount` = 订单面值（让上游校验，商品配错面值时直接驳回）。上游的 `order_number` 存为供应商订单号。
- 签名按第 2 节：`urldecode(http_build_query(升序参数)) . '&apikey=' . apikey`，md5 转大写。回调按收到的全部参数（去掉 `sign`）验签，并核对 `userid`，通过后回 `success`。
- 建供应商商品时可以从上游产品列表里选：在售 = `api_open` 为 1；运营商按 `isp` 换算（1 移动、2 电信、3 联通，虚拟不对应）；接口不给面值，要管理员自己填。

| 情况 | 结果 |
|---|---|
| 下单 `errno=0` | 处理中，等回调 / 查单 |
| 下单 `errno` 非 0 | 失败（上游明确「提交失败」），换下一个供应商 |
| 下单 HTTP 非 200、没有响应体、返回无法解析 | 处理中，等查单（不知道建没建单） |
| 下单网络超时、连不上等接口调用异常 | 立即转异常订单并标为人工处理单：上游结果只记录，状态和回调全部人工（见 docs/project.md 2.5） |
| 查单 / 回调 `state` 0 | 处理中 |
| 查单 / 回调 `state` 1 | 成功 |
| 查单 / 回调 `state` -1、2 | 失败 |
| 查单 / 回调 `state` 3 部分成功 | 处理中，记下原因，到时转异常人工处理（不能按成功也不能全额退款） |
| 查单 `errno` 非 0、查不到这笔单、没有状态 | 处理中，到时转异常人工处理 |
| 回调验签不通过、`userid` 对不上 | 不采用，回 `fail` |

## 10. 待和上游确认

1. 下单 `errno` 非 0 时是否一定没有建单（目前按没建单处理，直接换下一个供应商）。
2. 查单查不到订单时 `errno` 和 `data` 是什么。
3. 只查一个单号时 `data` 是对象还是数组（驱动两种都认）。
4. `isp` 是单个数字还是逗号分隔的集合（驱动两种都认）。
5. 部分成功（`state=3`）时实际到账多少、费用怎么结算。
