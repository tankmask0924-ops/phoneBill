# 智信科技接口说明

**商户接入手册**

（Version: 3.0.0）

| 版本 | 说明 | 修改日期 | 作者 |
| --- | --- | --- | --- |
| 2.0 | 新作成 | 2015.09.09 | 黄义声 |
| 2.1 | 1.新增异步通知签名字段排序 | 2015.09.16 | 黄义声 |
| 2.2 | 1.订单受理接口移除省份码tele_code字段<br>2.新增接口地址 | 2015.09.17 | 黄义声 |
| 2.3 | 1.订单查询接口新增‘订单时间’字段 | 2015.09.22 | 黄义声 |
| 2.3.1 | 1.余额查询接口余额字段以元为单位<br>2.订单受理接口提供唯一性校验 | 2015.11.16 | 黄义声 |
| 2.3.2 | 1.订单状态调整，移除订单查询接口，异常回调未下发的接口状态 | 2015.11.21 | 黄义声 |
| 2.3.3 | 1.余额查询，状态查询，预受理接口使用独立域名<br>2.新增接口说明、接口示例<br>3.新增接口返回码处理 | 2016.02.24 | 黄义声 |
| 2.3.4 | 1.修订‘返回码’ | 2016.03.03 | 黄义声 |
| 2.3.5 | 1.新增Q币提交接口 | 2016.06.18 | 陈辉德 |
| 2.4.0 | 1.订单查询接口&amp;回调通知新增desc失败描述字段 | 2016.08.19 | 黄义声 |
| 2.5.0 | 1.修订Q币提交接口 | 2017.07.20 | 黄义声 |
| 2.5.4 | 1.新增1日包 | 2018.06.07 | 黄义声 |
| 2.5.5 | 1.新增3日包，7日包 | 2018.08.17 | 黄义声 |
| 2.5.6 | 1.新增语音包、视频会员 | 2018.09.17 | 黄义声 |
| 2.5.7 | 1.新增渠道类型&lt;channel_type&gt;字段 | 2018.11.08 | 黄义声 |
| 2.5.8 | 1.新增‘常见问题’FAQ | 2018.11.29 | 黄义声 |
| 2.5.9 | 1.&lt;数娱产品&gt;订单提交接口新增&lt;buy_num&gt;字段 | 2019.03.07 | 黄义声 |
| 2.7.0 | 1.新增max_price可选字段，若满天星预扣款金额超过max_price参数，则订单受理失败 | 2019.07.26 | 黄义声 |
| 2.8.0 | 1.新增订单预检查接口(手机/宽带/固话/流量)，该接口为可选接口 | 2022.04.15 | 黄义声 |
| 3.0.0 | 1.新增卡券接口列表 | 2023.11.20 | 林勇 |

## 1 文档说明

### 1.1 编写目的

文档说明了智信科技虚拟充值的接口协议，供该接口的合作商和本项目的后续开发维护人员使用。

### 1.2 名词解释

| 商户代号 | 充值平台提供给合作商户的标识代号 |
| --- | --- |
| 商户密钥 | 充值平台提供给合作商户，用于数字签名的密钥串 |
| 数字签名 | 为防止数据在传输过程中被篡改，或其他人伪造，商户与平台间通讯信息均应进行数字签名。 |
| 回调通知 | 合作商户提供回调接口。充值平台在完成订单充值后会异步通知订单充值状态。 |

## 2 协议规范

使用HTTP协议，合作商户使用HTTP POST方式提交数据请求，使用JSON数据交换格式;

(请求头content-type设置为application/json;charset=UTF-8);

使用UTF-8编码方式；

合作商户和充值平台对交互数据作数据签名校验;

平台提供IP白名单设置，合作商户可申请绑定IP白名单;

## 3 签名算法

签名方法如下:

1. 把所有参数作为一个数组A，按照参数的字母升序排序，获得一个新数组B。 (其中参数sign不参与签名)

2. 对数组B按照'key1value1key2value2...'排列拼接，生成字符串C。

3. 在字符串C末尾拼接商户密钥(secret_key)，生成字符串D。

4. 对字符串D进行MD5计算，结果以小写表示。

## 4 通用接口列表

### 4.1 商户余额查询

- 接口地址

http://*****/zxpaycore/v2/balance

- 请求报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 商户代号 | mrch_no | Sting | 商户代号 | 是 | 20 |
| 请求时间 | request_time | String | 格式：yyyyMMddHHmmss | 是 | 14 |
| 签名数据 | sign | String | 数据签名 | 是 | 32 |

- 返回报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 返回码 | code | String | 返回码 | 是 | 10 |
| 消息描述 | message | String |  | 是 | 128 |
| 返回内容 | data | Object | JSON字符串，数据结构如下 | 否 |  |

data数据结构

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 通用保证金余额 | balance | String | 2300.0000(元) | 是 | 20 |

- 接口示例

```
请求:
{
    "request_time": "20160224103037",
    "mrch_no": "100012",
    "sign": "dba0fd20385f698d7ab11ed8936fbc08"
}
签名字段排序：{mrch_no, request_time}
签名字符串示例：
mrch_no100012request_time20160224103037商户密钥

返回:
{
    "code": "2",
    "data": {
        "balance": "45984.3000",
    },
    "message": "查询商户余额成功!"
}
```

## 5 直充接口列表

### 5.1 订单提交接口(手机/宽带/固话/流量)

- 接口地址

http://*****/zxpaycore/v2/recharge

- 请求报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 商户代号 | mrch_no | Sting | 商户代号 | 是 | 20 |
| 请求时间 | request_time | String | 格式：yyyyMMddHHmmss | 是 | 14 |
| 商户订单号 | client_order_no | String | 商户订单号，商户自己生成，保证唯一。最大长度不超过32位 | 是 | 32 |
| 产品类型 | product_type | int | 1:手机充值<br>2:宽带充值<br>3:固话充值<br>4:国内流量月包<br>11:国内流量1日包<br>13:国内流量3日包<br>14:国内流量7日包<br>15:国内流量5日包<br>M104：快手定向月包<br>M105：今日头条定向月包<br>M106：腾讯视频定向月包<br>M107：优酷定向月包<br>M108：爱奇艺定向月包 | 是 | 10 |
| 业务号码 | phone_no | String | 充值号码 | 是 | 20 |
| 运营商 | cp | String | 运营商信息<br>01: 移动<br>02: 电信<br>03: 联通<br>04: 广电<br>空值：以智信系统号码归属识别为准 | 是 | 2 |
| 地区码 | city_code | String | 地区码<br>若为宽带\固话，则必填。其他产品，可传空值 | 是 | 10 |
| 充值面额/流量规格 | recharge_amount | int | 话充：以分为单位<br>流量：以M为单位<br>语音包：以分钟数为单位 | 是 | 10 |
| 支付方式 | recharge_type | int | 支付方式：<br>0: 从预存金扣费 | 是 | 10 |
| 充值描述 | recharge_desc | String | 这是一笔什么的充值，可传空值 | 是 | 128 |
| 回调地址 | notify_url | String | 回调地址<br>若为空值，则平台不发起异步通知 | 是 | 128 |
| 最高可接受价格 | max_price | decimal | 以分为单位，支持两位小数。<br>若满天星预扣款金额超过max_price参数，则订单受理失败。 | 否 | (8,2) |
| 签名数据 | sign | String | 数字签名 | 是 | 32 |

- 返回报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 状态 | code | String | 返回码 | 是 | 10 |
| 消息描述 | message | String |  | 是 | 128 |
| 返回内容 | data | Object | JSON字符串，数据结构如下 | 否 |  |

data数据结构

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 平台订单号 | up_order_no | String | 充值平台订单号 | 是 | 32 |

- 接口示例

```
请求：
{
    "phone_no": "18559622286",
    "recharge_amount": 100,
    "request_time": "20160224103951",
    "product_type": 1,
    "client_order_no": "2016022410395100000001",
    "recharge_type": 0,
    "mrch_no": "100012",
    "recharge_desc": "testing",
    "sign": "005b0d1168cf30f515a2f20d8f35f07c",
    "city_code": "",
    "notify_url": "http://*****/zxpaycore/v2/testNotify",
    "cp": ""
}

签名字段排序：[city_code, client_order_no, cp, mrch_no, notify_url, phone_no, product_type, recharge_amount, recharge_desc, recharge_type, request_time]
签名字符串示例:
city_codeclient_order_no2016022410395100000001cpmrch_no100012notify_urlhttp://*****/zxpaycore/v2/testNotifyphone_no18559622286product_type1recharge_amount100recharge_desctestingrecharge_type0request_time20160224103951商户密钥

返回：
{
    "code": "2",
    "data": {
        "up_order_no": "20160224103952765560633"
    },
    "message": "操作成功"
}
```

### 5.2 订单提交接口(数娱/游戏/出行)

- 接口地址

http://*****/zxpaycore/v2/preorder

- 请求报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 商户代号 | mrch_no | Sting | 商户代号 | 是 | 20 |
| 请求时间 | request_time | String | 格式：yyyyMMddHHmmss | 是 | 14 |
| 商户订单号 | client_order_no | String | 商户订单号，商户自己生成，保证唯一。最大长度不超过32位 | 是 | 32 |
| 业务号码 | account | String | 充值号码 | 是 | 20 |
| 产品编码 | product_code | String | 产品编码 | 是 | 10 |
| 购买数量 | buy_num | int | 正常商品，传递数量1； | 是 | 5 |
| 其他参数 | other_param | String | JSON字符串，游戏参数详见下表，其他充值类型，可传空值 | 是 | 128 |
| 回调地址 | notify_url | String | 回调地址<br>若为空值，则平台不发起异步通知 | 是 | 128 |
| 最高可接受价格 | max_price | decimal | 以分为单位，支持两位小数。<br>若满天星预扣款金额超过max_price参数，则订单受理失败。 | 是 | (8,2) |
| 签名数据 | sign | String | 数字签名 | 是 | 32 |

- other_param参数

游戏充值：

| 充值游戏区 | game_region | Sting | 充值游戏区 | 否 | 32 |
| --- | --- | --- | --- | --- | --- |
| 充值游戏服 | game_server | String | 充值游戏服 | 否 | 32 |
| 客户端ip | client_ip | String | 客户端ip | 是 | 32 |

- 返回报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 状态 | code | String | 返回码 | 是 | 10 |
| 消息描述 | message | String |  | 是 | 128 |
| 返回内容 | data | Object | JSON字符串，数据结构如下 | 否 |  |

data数据结构

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 平台订单号 | up_order_no | String | 充值平台订单号 | 是 | 32 |

- 接口示例

```
请求：(爱奇艺黄金会员周卡示例)
{
	"mrch_no": "100668",
	"request_time": "20180911205817",
	"client_order_no": "2018091120580157017926",
	"account": "18559622286",
	"product_code": "A108017",
    	"buy_num": "1",
	"other_param": "",
	"notify_url": "http://*****/testNotify",
     "max_price": "1000.00",
	"sign": "b3e7909343565227d62f5af481cea38e"
}
签名字段排序：[account, buy_num, client_order_no, max_price, mrch_no, notify_url, other_param, product_code, request_time]
签名字符串示例:
account18559622286buy_num1client_order_no2018091120580157017926max_price1000.00mrch_no100012notify_urlhttp://*****/testNotifyother_paramproduct_codeA108017request_time20160224103951商户密钥

返回：
{
    "code": "2",
    "data": {
        "up_order_no": "20160224103952765560633"
    },
    "message": "操作成功"
}
```

### 5.3 Q币提交接口

- 接口地址

http://*****/zxpaycore/v2/preorder

- 请求报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 商户编号 | mrch_no | String | 商户编号 | 是 | 20 |
| 请求时间 | request_time | String | 格式：yyyyMMddHHmmss | 是 | 14 |
| 商户订单号 | client_order_no | String | 商户订单号，商户自己生成，保证唯一。最大长度不超过32位 | 是 | 32 |
| 产品类型 | product_type | String | 6:Q币充值 | 是 | 10 |
| 玩家账号 | account | String | QQ号码 | 是 | 20 |
| 充值规格 | amount | String | Q币数量<br>若充值1Q币，则提交1 | 是 | 10 |
| 其他参数 | other_param | String | 客户端IP地址，可以为空值 | 是 | 20 |
| 回调地址 | notify_url | String | 回调地址<br>若为空值，则无回调通知 | 否 | 128 |
| 签名数据 | sign | String | 数据签名 | 是 | 32 |

- 返回报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 状态 | code | String | 返回码 | 是 | 10 |
| 消息描述 | message | String |  | 是 | 128 |
| 返回内容 | data | Object | JSON字符串，数据结构如下 | 否 |  |

data数据结构

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 平台订单号 | up_order_no | String | 充值平台订单号 | 是 | 32 |

- 接口示例

```
请求：
{
    "other_param": "*****",
    "amount": 1,
    "request_time": "20170906162349",
    "product_type": 6,
    "client_order_no": "2017090616234900000001",
    "mrch_no": "100012",
    "sign": "a461fc94daeefc9501b1b2945d092fdb",
    "notify_url": "http://*****/zxpaycore/v2/testNotify",
    "account": "406390171"
}
签名字段排序：[account, amount, client_order_no, mrch_no, notify_url, other_param, product_type, request_time]
签名字符串示例:
account406390171amount1client_order_no2017090616234900000001mrch_no100012notify_urlhttp://***** /zxpaycore/v2/testNotifyother_param*****product_type6request_time20170906162349商户密钥

返回：
{
    "code": "2",
    "data": {
        "up_order_no": "20170906162346243140471"
    },
    "message": "操作成功"
}
```

### 5.4 直充订单查询

- 接口地址

http://*****/zxpaycore/v2/query

- 请求报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 商户编号 | mrch_no | String | 商户代号 | 是 | 20 |
| 请求时间 | request_time | String | 格式：yyyyMMddHHmmss | 是 | 14 |
| 商户订单号 | client_order_no | String |  | 是 | 32 |
| 订单下单时间 | order_time | String | 格式：yyyyMMddHHmmss<br>注意：<br>1. 充值平台从order_time字段截取订单日期T(yyyyMMdd)，查询该日期的订单数据。若无订单，则尝试查询(T-1)及(T+1)日期的订单数据。<br>2. 仅提供近1个月的订单数据 | 是 | 14 |
| 签名数据 | sign | String | 数据签名 | 是 | 32 |

- 返回报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 状态 | code | String | 返回码 | 是 | 10 |
| 消息描述 | message | String |  | 是 | 128 |
| 返回内容 | data | Object | JSON字符串，数据结构如下 | 否 |  |

data数据结构

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 商户订单号 | client_order_no | String | 商户订单号 | 是 | 32 |
| 平台订单号 | up_order_no | String | 充值平台订单号 | 是 | 32 |
| 产品类型 | product_type | String | 产品类型 | 是 | 10 |
| 充值号码 | phone_no | String | 充值号码 | 是 | 20 |
| 折扣金额 | deduction_amount | String | 折扣金额，以分为单位 | 是 | 20 |
| 订单状态 | recharge_status | String | 1:交易中<br>2:交易成功<br>6:交易失败 | 是 | 10 |
| 充值凭证 | elecardID | String | 运营商充值凭证 | 否 | 128 |
| 充值描述 | desc | String | 订单充值描述，如订单失败原因 | 否 | 256 |
| 渠道类型 | channel_type | String | 渠道编码(参考附录8) | 否 | 10 |

- 接口示例

```
请求：
{
    "request_time": "20160224104435",
    "client_order_no": "2015122415075700000001",
    "mrch_no": "100012",
    "sign": "b6a826bde264a11921ea0934a7d865e6",
    "order_time": "20151224161330"
}
签名字段排序：[client_order_no, mrch_no, order time, request_time]
签名字符串:
client_order_no2015122415075700000001mrch_no100012order_time20151224161330request_time20160224104435商户密钥

返回：
{
    "code": "2",
    "data": {
        "client_order_no": "2015122415075700000001",
        "deduction_amount": "267.0000",
        "phone_no": "18559622286",
        "product_type": "4",
        "recharge_status": "6",
        "desc": "业务禁用",
        "up_order_no": "20151224150841723471453"
    },
    "message": "操作成功"
}
```

### 5.5 直充回调通知接收

- 接口地址

回调通知接收地址由合作商户提供，可通过订单提交接口notify_url字段传递。

- 请求报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 商户订单号 | client_order_no | String | 商户订单号 | 是 | 32 |
| 平台订单号 | up_order_no | String | 充值平台订单号 | 是 | 32 |
| 产品类型 | product_type | String | 产品类型 | 是 | 10 |
| 充值号码 | phone_no | String | 充值号码 | 是 | 20 |
| 折扣金额 | deduction_amount | String | 折扣金额 | 是 | 20 |
| 订单状态 | recharge_status | String | 2:交易成功<br>6:交易失败 | 是 | 10 |
| 充值凭证 | elecardID | String | 运营商充值凭证 | 否 | 128 |
| 充值描述 | desc | String | 失败描述(该字段不参与签名)<br>使用UTF-8 URLDECODE编码 | 否 | 256 |
| 渠道类型 | channel_type | String | 渠道编码(该字段不参与签名)<br>参考附录8 | 否 | 10 |
| 签名数据 | sign | String | 数据签名 | 是 | 32 |

说明：

1. 充值平台在完成订单充值后，向合作商户回调订单结果，请求方式为HTTP GET。

2. 合作商户在接收到回调通知后，请返回字符串SUCCESS。

3. 如通知失败，充值平台每隔5分钟进行通知，3次通知均失败后不再通知。

- 接口示例

```
通知请求：
http://*****/notify?client_order_no=1880332812&up_order_no=20160819153639354135487&product_type=4&phone_no=17777784614&deduction_amount=166.0000&recharge_status=6&desc=%e6%ac%a0%e8%b4%b9%2f%e5%81%9c%e6%9c%ba&sign=79e994444e6c3663e76dcf90d74a3312

返回：
SUCCESS

签名字段排序:
[client_order_no, deduction_amount, elecardID, phone_no, product_type, recharge_status, up_order_no]
```

## 6 卡券接口列表

### 6.1 卡券提取接口(商超卡/权益卡)

- 接口地址

http://*****/zxpaycore/cardpwd/preorder

- 请求报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 商户代号 | mrch_no | Sting | 商户代号 | 是 | 32 |
| 请求时间 | request_time | String | 格式：yyyyMMddHHmmss | 是 | 14 |
| 商户订单号 | client_order_no | String | 商户订单号，商户自己生成，保证唯一。最大长度不超过32位 | 是 | 32 |
| 产品编码 | product_code | String | 产品编码 | 是 | 16 |
| 购买数量 | buy_num | int | 大于0 | 是 | 8 |
| 其他参数 | other_param | String | 其他参数，可传空值 | 是 | 128 |
| 回调地址 | notify_url | String | 回调地址<br>若为空值，则平台不发起异步通知 | 是 | 128 |
| 最高可接受价格 | max_price | decimal | 以分为单位，支持两位小数。<br>若满天星预扣款金额超过max_price参数，则订单受理失败。 | 否 | (8,2) |
| 充值描述 | recharge_desc | String | 这是一笔什么的充值，可传空值 | 是 | 128 |
| 签名数据 | sign | String | 数字签名 | 是 | 32 |

- 返回报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 状态 | code | String | 返回码 | 是 | 8 |
| 消息描述 | message | String |  | 是 | 128 |
| 返回内容 | data | Object | JSON字符串，数据结构如下 | 否 |  |

data数据结构

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 平台订单号 | up_order_no | String | 充值平台订单号 | 是 | 32 |

- 接口示例

```
请求：
{
	"mrch_no": "100668",
	"request_time": "20180911205817",
	"client_order_no": "2018091120580157017926",
	"product_code": "A108017",
    	"buy_num": "1",
	"other_param": "",
	"notify_url": "http://*****/testNotify",
     "max_price": "1000.00",
	"sign": "b3e7909343565227d62f5af481cea38e"
}
签名字段排序：[buy_num, client_order_no, max_price, mrch_no, notify_url, other_param, product_code, request_time]
签名字符串示例:
buy_num1client_order_no2018091120580157017926max_price1000.00mrch_no100012notify_urlhttp://*****/testNotifyother_paramproduct_codeA108017request_time20160224103951商户密钥

返回：
{
    "code": "2",
    "data": {
        "up_order_no": "20160224103952765560633"
    },
    "message": "操作成功"
}
```

### 6.2 卡券订单查询

- 接口地址

http://*****/zxpaycore/v2/query

- 请求报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 商户编号 | mrch_no | String | 商户代号 | 是 | 16 |
| 请求时间 | request_time | String | 格式：yyyyMMddHHmmss | 是 | 14 |
| 商户订单号 | client_order_no | String |  | 是 | 32 |
| 订单下单时间 | order_time | String | 格式：yyyyMMddHHmmss<br>注意：<br>1. 充值平台从order_time字段截取订单日期T(yyyyMMdd)，查询该日期的订单数据。若无订单，则尝试查询(T-1)及(T+1)日期的订单数据。<br>2. 仅提供近1个月的订单数据 | 是 | 14 |
| 签名数据 | sign | String | 数据签名 | 是 | 32 |

- 返回报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 状态 | code | String | 返回码 | 是 | 8 |
| 消息描述 | message | String |  | 是 | 128 |
| 返回内容 | data | Object | JSON字符串，数据结构如下 | 否 |  |

data数据结构

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 商户订单号 | client_order_no | String | 商户订单号 | 是 | 32 |
| 平台订单号 | up_order_no | String | 充值平台订单号 | 是 | 32 |
| 产品类型 | product_type | String | 产品类型（产品编码） | 是 | 16 |
| 折扣金额 | deduction_amount | String | 折扣金额，以分为单位 | 是 | 32 |
| 订单状态 | recharge_status | String | 1:交易中<br>2:交易成功<br>6:交易失败 | 是 | 8 |
| 购买数量 | buy_num | int | 购买数量 | 是 | 8 |
| 标准价格 | unit_price | int | 标准价格，以分为单位 | 是 | 32 |
| 卡密列表 | card_data | array | 卡密列表 |  |  |
|  | card_type | String | LINK 链接, PICTURE 券码+链接, NUMBER_PASSWORD 卡号+密码, PASSWORD 密码 | 是 | 32 |
|  | card_no | String | 卡号(卡密类型为NUMBER_PASSWORD时有值) | 否 | 32 |
|  | card_pwd | String | 密码(卡密类型为NUMBER_PASSWORD、PASSWORD、PICTURE时有值)<br>(密文加密，请参考第四小点:卡信息解密算法) | 否 | 64 |
|  | card_link | String | 链接(卡密类型为LINK或PICTURE时有值)<br>(密文加密，请参考第四小点:卡信息解密算法) | 否 | 256 |
|  | expire_time | String | 过期时间yyyy-MM-dd HH:mm:ss | 否 | 32 |
| 充值描述 | desc | String | 订单充值描述，如订单失败原因 | 否 | 256 |

- 接口示例

```
请求：
{
    "request_time": "20160224104435",
    "client_order_no": "2015122415075700000001",
    "mrch_no": "100012",
    "sign": "b6a826bde264a11921ea0934a7d865e6",
    "order_time": "20151224161330"
}

返回：
{
    "code": "2",
    "data": {
        "client_order_no": "2015122415075700000001",
        "up_order_no": "20151224150841723471453"
        "product_type": "K102210",
        "deduction_amount": "1000.0000",
        "recharge_status": "2",
        "buy_num": 1,
        "unit_price": 1000,
        "card_data": [
             {
               "card_no": "6212382758745847",
               "card_pwd": "9546de4ad292447e32f62ebeafcd1bfc",
               "card_type": "NUMBER_PASSWORD"
             }
         ]
    },
    "message": "操作成功"
}
```

### 6.3 卡券回调通知接收

- 接口地址

回调通知接收地址由合作商户提供，可通过订单提交接口notify_url字段传递。

- 请求报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 商户订单号 | client_order_no | String | 商户订单号 | 是 | 32 |
| 平台订单号 | up_order_no | String | 充值平台订单号 | 是 | 32 |
| 产品类型 | product_type | String | 产品类型 | 是 | 16 |
| 折扣金额 | deduction_amount | String | 折扣金额 | 是 | 32 |
| 订单状态 | recharge_status | String | 2:交易成功<br>6:交易失败 | 是 | 8 |
| 购买数量 | buy_num | int | 购买数量 | 是 | 8 |
| 标准价格 | unit_price | int | 标准价格，以分为单位 | 是 | 32 |
| 卡密列表 | card_data | array | 卡密列表 |  |  |
|  | card_type | String | LINK 链接, PICTURE 券码+链接, NUMBER_PASSWORD 卡号+密码, PASSWORD 密码 | 是 | 32 |
|  | card_no | String | 卡号(卡密类型为NUMBER_PASSWORD时有值) | 否 | 32 |
|  | card_pwd | String | 密码(卡密类型为NUMBER_PASSWORD、PASSWORD、PICTURE时有值)<br>(密文加密，请参考第四小点:卡信息解密算法) | 否 | 64 |
|  | card_link | String | 链接(卡密类型为LINK或PICTURE时有值)<br>(密文加密，请参考第四小点:卡信息解密算法) | 否 | 256 |
|  | expire_time | String | 过期时间yyyy-MM-dd HH:mm:ss | 否 | 32 |
| 充值描述 | desc | String | 失败描述(该字段不参与签名)<br>使用UTF-8 URLDECODE编码 | 否 | 256 |
| 签名数据 | sign | String | 数据签名 | 是 | 32 |

说明：

1. 充值平台在完成订单充值后，向合作商户回调订单结果，请求方式为HTTP POST。

2. 合作商户在接收到回调通知后，请返回字符串SUCCESS。

3. 如通知失败，充值平台每隔5分钟进行通知，3次通知均失败后不再通知。

示例：

```
签名字段排序:
[buy_num, card_data, client_order_no, deduction_amount, product_type, recharge_status, unit_price, up_order_no, 商户密钥]
```

### 6.4 卡券解密算法

- 卡券数据使用加密传输

解密算法：DES/ECB/PKCS5Padding

解密密钥：商户密钥

- 解密示例

```
密文：378db882adb8f515
密钥：XpeK3AcVBW6URunuxkBo9
解密后明文：123456
```

## 7 流量订购（短信二次验证）接口列表

### 7.1 订单预提交（发送短信）

- 接口地址

http://*****/zxpaycore/v2/sendCode

- 请求报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 商户代号 | mrch_no | Sting | 商户代号 | 是 | 20 |
| 请求时间 | request_time | String | 格式：yyyyMMddHHmmss | 是 | 14 |
| 商户订单号 | client_order_no | String | 商户订单号，商户自己生成，保证唯一。最大长度不超过32位 | 是 | 32 |
| 产品类型 | product_type | int | 1:手机充值<br>2:宽带充值<br>3:固话充值<br>4:国内流量月包<br>11:国内流量1日包<br>13:国内流量3日包<br>14:国内流量7日包<br>15:国内流量5日包<br>M104：快手定向月包<br>M105：今日头条定向月包<br>M106：腾讯视频定向月包<br>M107：优酷定向月包<br>M108：爱奇艺定向月包 | 是 | 10 |
| 业务号码 | phone_no | String | 充值号码 | 是 | 20 |
| 运营商 | cp | String | 运营商信息<br>01: 移动<br>02: 电信<br>03: 联通<br>04: 广电<br>空值：以智信系统号码归属识别为准 | 是 | 2 |
| 充值面额/流量规格 | recharge_amount | int | 话充：以分为单位<br>流量：以M为单位<br>语音包：以分钟数为单位 | 是 | 10 |
| 回调地址 | notify_url | String | 回调地址<br>若为空值，则平台不发起异步通知 | 是 | 128 |
| 最高可接受价格 | max_price | decimal | 以分为单位，支持两位小数。<br>若满天星预扣款金额超过max_price参数，则订单受理失败。 | 否 | (8,2) |
| 签名数据 | sign | String | 数字签名 | 是 | 32 |

- 返回报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 状态 | code | String | 返回码 | 是 | 10 |
| 消息描述 | message | String |  | 是 | 128 |
| 返回内容 | data | Object | JSON字符串，数据结构如下 | 否 |  |

data数据结构

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 平台订单号 | up_order_no | String | 充值平台订单号 | 是 | 32 |

- 接口示例

```
请求：
{
    "phone_no": "18559622286",
    "recharge_amount": 100,
    "request_time": "20160224103951",
    "product_type": 1,
    "client_order_no": "2016022410395100000001",
    "mrch_no": "100012",
    "sign": "005b0d1168cf30f515a2f20d8f35f07c",
    "notify_url": "http://*****/zxpaycore/v2/testNotify",
    "cp": ""
}

签名字段排序：[client_order_no, cp, mrch_no, notify_url, phone_no, product_type, recharge_amount, request_time]
签名字符串示例:
client_order_no2016022410395100000001cpmrch_no100012notify_urlhttp://*****/zxpaycore/v2/testNotifyphone_no18559622286product_type1recharge_amount100request_time20160224103951商户密钥

返回：
{
    "code": "2",
    "data": {
        "up_order_no": "20160224103952765560633"
    },
    "message": "操作成功"
}
```

### 7.2 订单确认

- 接口地址

http://*****/zxpaycore/v2/confirmOrder

- 请求报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 商户代号 | mrch_no | Sting | 商户代号 | 是 | 20 |
| 请求时间 | request_time | String | 格式：yyyyMMddHHmmss | 是 | 14 |
| 商户订单号 | client_order_no | String | 商户订单号，商户自己生成，保证唯一。最大长度不超过32位 | 是 | 32 |
| 业务号码 | phone_no | String | 充值号码 | 是 | 20 |
| 其他参数 | other_param | String | 短信验证码 | 是 | 128 |
| 签名数据 | sign | String | 数字签名 | 是 | 32 |

- 返回报文格式

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 状态 | code | String | 返回码 | 是 | 10 |
| 消息描述 | message | String |  | 是 | 128 |
| 返回内容 | data | Object | JSON字符串，数据结构如下 | 否 |  |

data数据结构

| 参数名称 | 参数命名 | 参数类型 | 备注 | 必填 | 长度 |
| --- | --- | --- | --- | --- | --- |
| 平台订单号 | up_order_no | String | 充值平台订单号 | 是 | 32 |

- 接口示例

```
请求：
{
    "phone_no": "18559622286",
    "request_time": "20160224103951",
    "client_order_no": "2016022410395100000001",
    "mrch_no": "100012",
    "other_param": "123456",
    "sign": "005b0d1168cf30f515a2f20d8f35f07c"
}

签名字段排序：[client_order_no, mrch_no, other_param, phone_no,  request_time]
签名字符串示例:
client_order_no2016022410395100000001mrch_no100012other_param123456phone_no18559622286request_time20160224103951商户密钥

返回：
{
    "code": "2",
    "data": {
        "up_order_no": "20160224103952765560633"
    },
    "message": "操作成功"
}
```

## 8 返回码

### 8.1 订单提交接口返回码

| 返回码Code | 描述 | 接口 | 处理 |
| --- | --- | --- | --- |
| 2 | 操作成功 | 订单提交 | 提交成功，订单处理中 |
| 600 | 商户禁用,接口已关闭 | 订单提交 | 失败 |
| 602 | 订单提交失败，未充值 | 订单提交 | 失败 |
| 603 | 参数校验错误 | 订单提交 | 失败 |
| 606 | 数据签名错误 | 订单提交 | 失败 |
| 615 | 号码归属地未配置 | 订单提交 | 失败 |
| 622 | 商户不存在 | 订单提交 | 失败 |
| 623 | 通道维护 | 订单提交 | 失败 |
| 624 | 产品未配置 | 订单提交 | 失败 |
| 637 | 流量充值未配置 | 订单提交 | 失败 |
| 751 | IP地址限制 | 订单提交 | 失败 |
| 625 | 重复订单号 | 订单提交 | 存疑<br>查询订单状态或人工核实 |
| 其他返回码 | 网络问题导致提交异常或其他返回码 | 订单提交 | 存疑<br>查询订单状态或人工核实 |

### 8.2 订单查询接口返回码

| 返回码Code | 描述 | 接口 | 处理 |
| --- | --- | --- | --- |
| 2 | 操作成功 | 订单状态查询 | 操作成功，根据recharge_status判定订单状态<br>recharge_status:1,交易中<br>recharge_status:2,交易成功<br>recharge_status:6,交易失败 |
| 626 | 订单不存在 | 订单状态查询 | 请检查order_time(订单下单时间)字段是否准确，该字段影响订单查询结果。<br>在订单提交10分钟后，订单查询接口仍未查询到订单信息，订单可作失败处理 |
| 603 | 请求数据不正确或查询异常 | 订单状态查询 | 再次查询或人工核实。<br>若返回该状态码，不能做订单失败处理。 |
| 606 | 数据签名错误 | 订单状态查询 |  |
| 751 | IP地址限制 | 订单状态查询 |  |
| 其他返回码 | 网络问题导致提交异常或其他返回码 | 订单状态查询 |  |

### 8.3 回调通知返回码

| 返回码Code | 描述 | 接口 | 处理 |
| --- | --- | --- | --- |
| 2 | 订单交易成功 | 回调通知 | 订单成功 |
| 6 | 订单交易失败 | 回调通知 | 订单失败 |
| 其他 | 订单交易中 | 回调通知 | 调用订单查询接口或人工核实 |

## 9 常见问题

1. [接口返回码：603，返回描述：请求数据格式不正确]

请使用HTTP POST方式提交数据请求，数据交换格式为JSON。另外设置请求头content-type设置为application/json;charset=UTF-8;

2. [接口返回码：603，返回描述：参数request_time超过限制或格式错误]

请求时间request_time与标准时间相差不得超过5min。

3. [商户流水号长度不超过32位]

4. [订单提交接口]

网络问题导致请求异常或返回其他响应码，商户需要再次查询或人工核实订单状态。

5. [订单状态查询]

网络问题导致请求异常或返回其他响应码，商户需要再次查询或人工核实订单状态。

6. [订单状态查询]

参数request_time为该HTTP请求的发起时间;参数order_time为被查询订单的订单提交时间。请注意区分字段含义，避免查询不准确。

## 10 话充渠道类型

| 编码 | 渠道 |
| --- | --- |
| sz | 手支 |
| kc | 空充 |
| km | 卡密 |
| sbosszl | 省boss直连 |
| wzf | 沃支付 |
| jfjk | 计费接口 |
| other | 其他 |

## 我们的对接方式（驱动 `zhixin`）

- 接口参数：接口域名（`http://上游域名`，下单 `/zxpaycore/v2/recharge`、查单 `/zxpaycore/v2/query`、余额 `/zxpaycore/v2/balance` 写在驱动里）、查单 / 余额域名（选填，2.3.3 说这几个接口可能用独立域名，上游给了才填，留空同接口域名）、商户代号（`mrch_no`）、商户密钥；回调地址自动生成（`回调域名/notify/supplier/{供应商编码}`），每单随下单传 `notify_url`。
- 只接话费（5.1，`product_type=1`）。字段对应：`client_order_no` = 提交单号 `attempt_no`（24 位，不超过 32 位），`phone_no` = 手机号，`recharge_amount` = 面值 × 100（分），`cp` 01 移动、02 电信、03 联通、04 广电，`city_code` / `recharge_desc` 传空，`recharge_type` 固定 `0`，不传 `max_price`。上游返回的 `up_order_no` 存为供应商订单号。
- 全部接口 POST JSON，每次带 `mrch_no` 和北京时间 `request_time`（`yyyyMMddHHmmss`，和上游相差超过 5 分钟会被拒）。签名按第 3 节：去掉 `sign`，按参数名升序拼 `key1value1key2value2...`（空值也参与，只拼字段名），末尾拼商户密钥，md5 小写。
- 查单的 `order_time` 取提交单号开头 14 位（订单号里的下单时间，北京时间）；上游会查这一天和前后各一天，只保留近 1 个月。
- 回调是 GET：按 `client_order_no`、`deduction_amount`、`elecardID`、`phone_no`、`product_type`、`recharge_status`、`up_order_no` 里实际收到的字段验签（`desc`、`channel_type` 不参与），通过回 `SUCCESS`，不通过回 `fail`。
- 余额取 `data.balance`（元）。

| 情况 | 结果 |
|---|---|
| 下单 `code=2` | 处理中，等回调 / 查单 |
| 下单 `code` 不是 2（含 600、602、603、606、615、622、623、624、637、751，以及文档写「存疑」的 `625` 重复订单号和表外的码） | 失败，换下一个供应商（2026-10-25 定：只有 2 算受理，上游有返回码、不是 2 就判失败） |
| 下单网络超时、连不上、HTTP 出错、返回无法解析或没有 `code`（没有拿到返回值） | 立即转异常订单并标为人工处理单：上游结果只记录，状态和回调全部人工（见 docs/project.md 2.5） |
| 查单 `code=2` 且 `recharge_status=2` | 成功 |
| 查单 `code=2` 且 `recharge_status=6` | 失败（`desc` 作为失败原因） |
| 查单 `code=2` 且 `recharge_status=1` 或其他 | 处理中 |
| 查单 `626` 订单不存在 | 只记录到这次提交上，订单状态不动（下单受理了就以下单为准）；一直没结果的到时转异常人工处理 |
| 查单 `603`、`606`、`751`、表外的码、返回订单号对不上、没拿到返回 | 处理中，不处理订单（文档：603 不能做失败处理） |
| 回调 `recharge_status=2` / `6` | 成功 / 失败 |
| 回调其他 `recharge_status` | 回 `SUCCESS`，不处理订单 |

## 待和上游确认

1. 回调没有 `elecardID` 时，签名是不拼这个字段（我们现在的做法），还是拼 `elecardID` + 空值。
2. 查单 / 余额是否真的要用独立域名。
