# Turbo TOPUP 接口文档

> 接口分组：`intrgration`

## 目录

- [一、接口说明](#一接口说明)
- [二、请求签名详细步骤](#二请求签名详细步骤)
- [三、Node.js 生成请求签名示例](#三nodejs-生成请求签名示例)
- [四、Node.js 请求示例](#四nodejs-请求示例)
- [五、常见问题（3.EQ）](#五常见问题3eq)
- [六、状态说明](#六状态说明)
- [七、接口详情](#七接口详情)
  - [1. 账户信息](#1-账户信息)
  - [2. 产品信息](#2-产品信息)
  - [3. 下单](#3-下单)
  - [4. 订单查询](#4-订单查询)
  - [5. 号码识别运营商](#5-号码识别运营商)
  - [6. 回调](#6-回调)

---

## 一、接口说明

### TOPUP 接口说明

### 准备工作

代理后台：

- 接口域名：代理后台为准）
- accessKey（用户后台获取）
- secretKey（对接密钥，用户后台获取，可用于生成签名和验签）

---

## 二、请求签名详细步骤

**除了 sign 字段不参与签名，其他字段都需要进行键名升序后使用 MD5 大写。**

```js
1.查看下单接口所需参数：
{
    "accessKey": "c50dc8a657d3bfa7a76786983a71dc0c",  
    "productId": "P000014", // 套餐ID  后台查看或者调用产品信息接口
    "phone": "15186817855", // 充值号码 
    "externalOrderNo":"123456", // 接入方订单号
    "notificationUrl":"回调地址" // 回调地址 具体查看回调详情
}
2.将sign不参与签名外的所有键名sort排序  a-b-c-d 的顺序,然后在最后面拼接secretKey （在后台可以获取）成签名串：

accessKey=c50dc8a657d3bfa7a7606983a71dc0c&externalOrderNo=TR900000001&notificationUrl=https://www/baidu.com&phone=17305318794&productId=P000019&secretKey=8b2f29dd009de4d23e0d76bbde54e146f0653cff5a6b01a10cb1418f104f8

3.使用MD5并且大写生成签名值
签名值：93352AAFE9254EB87A0424F106C0FB81 （类似）

组建出下单最终参数：
{
    "accessKey": "c50dc8a657d3bfa0786983a71dc0c",
    "productId": "P000019",
    "externalOrderNo": "TR900000001",
    "phone": "17385018794",
    "notificationUrl": "https://www/baidu.com",
    "sign": "63FB3C8959FAA63AE02BE363AAD3626"
}
```

---

## 三、Node.js 生成请求签名示例

其他语言自行编写。

```js
/**
 * 生成请求签名
 *
 * @param {Object} params - 待签名的参数对象，包含键值对
 * @param {String} secretKey - 用于生成签名的密钥
 * @returns {String} - 生成的签名（大写的MD5值）
 *
 * 该函数首先过滤掉未定义或为null的参数，然后对剩余参数的键进行升序排序，
 * 生成一个查询字符串，最后拼接时间戳和密钥，通过MD5算法生成签名并返回。
 */
function generateSignature(params, secretKey) {
    // 参数校验
    if (typeof params !== 'object' || params === null) {
        throw new Error('Params must be an object');
    }
    if (typeof secretKey !== 'string') {
        throw new Error('SecretKey must be a string');
    }

    // 过滤非空参数并排除sign字段
    const filteredParams = Object.entries(params).reduce((acc, [key, value]) => {
        if (value !== undefined && value !== null && key !== 'sign') {
            acc[key] = value;
        }
        return acc;
    }, {});

    // 按照键名字母升序排序
    const sortedKeys = Object.keys(filteredParams).sort();
    const queryString = sortedKeys
        .map(key => `${key}=${filteredParams[key]}`)
        .join('&');
    // 拼接时间戳
    const stringA = `${queryString}&secretKey=${secretKey}`;
    // 生成 MD5 签名并转换为大写
    return crypto.createHash('md5').update(stringA).digest('hex').toUpperCase();
}
```

---

## 四、Node.js 请求示例

其他语言自行编写。

```js
const axios = require('axios');

axios.post('{接口域名}/integration/v1/checkout', {
    "accessKey": "c50dc8a657d3bfa0786983a71dc0c",
    "productId": "P000019",
    "externalOrderNo": "TR900000001",
    "phone": "17385018794",
    "notificationUrl": "https://www/baidu.com",
    "sign": "63FB3C8959FAA63AE02BE363AAD3626"
})
```

---

## 五、常见问题（3.EQ）

### 1. 签名无效

- 排查是否有空值参数参与签名
- 用户通行 key 是否正确

---

## 六、状态说明

### 1. HTTP 状态码

```js
http status:
 200 正常响应
 400 参数校验失败
 404 接口不存在
 401 未授权 ：token身份认证未通过
 403 用户权限不足 ：没有访问特定资源的权限 菜单权限等用户的权限相关验证未通过
 500 服务器内部错误
```

### 2. 成功响应

```json
{
    "status": "success",
    "message": "Request succeeded",
    "data": {
        "orderNo": "TD20250423102005819",
        "orderStatus": "Processing",
        "rechargeStatus": 0,
        "externalOrderNo": "TR900000001"
    },
    "error": null
}
```

### 3. 失败错误响应

```json
{
  "status": "error",
  "message": "REQUEST_EXPIRED",
  "data": null,
  "error": {
    "code": "REQUEST_EXPIRED",
    "details": "请求过期"
  }
}
```

### 4. 提交下单成功后的订单状态说明

```js
Created: 已创建 订单创建后分配的初始状态，订单准备处理。
Processing: 已发起 系统已接受订单，正在处理中。订单向供应商发起
Succeeded: 成功 订单成功完成，所有操作或交易正常执行。
Failed: 失败 订单处理时遇到错误，未能成功完成。
Cancel: 取消订单 订单处理时遇到错误，未能成功完成。
```

---

## 七、接口详情

### 1. 账户信息

- **请求方式**：`POST`
- **接口路径**：`/integration/v1/accounts`
- **接口分组**：`intrgration`

#### 请求参数（Body，`application/json`）

| 参数名 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| accessKey | string | 是 | - |
| sign | string | 是 | - |

#### 响应参数

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| status | string | - |
| message | string | - |
| data | object | - |
| data.account | string | - |
| data.fullName | string | - |
| data.currency | string | - |
| data.balance | string | - |
| data.isActive | boolean | - |
| error | null | - |

#### 响应示例

```json
{
    "status": "success",
    "message": "Request succeeded",
    "data": {
        "account": "cyd",
        "fullName": "小李子",
        "currency": "CNY",
        "balance": "6300.65",
        "isActive": true
    },
    "error": null
}
```

---

### 2. 产品信息

- **请求方式**：`POST`
- **接口路径**：`/integration/v1/catalog`
- **接口分组**：`intrgration`

#### 请求参数（Body，`application/json`）

| 参数名 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| accessKey | string | 是 | 用户通行key，用户后台获取 |
| sign | string | 是 | 签名，详见签名说明 |

#### 响应参数

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| status | string | - |
| message | string | - |
| data | object | - |
| data.result | array | - |
| data.result[].productId | string | - |
| data.result[].productName | string | - |
| data.result[].brandId | object | - |
| data.result[].brandId._id | string | - |
| data.result[].brandId.brandId | string | - |
| data.result[].brandId.brandName | string | - |
| data.result[].cateId | array | - |
| data.result[].cateId[]._id | string | - |
| data.result[].cateId[].cateId | string | - |
| data.result[].cateId[].cateName | string | - |
| data.result[].premiumPercentage | integer | - |
| data.result[].updatedAt | string | - |
| data.result[].pType | string | - |
| data.result[].faceValue | integer | - |
| data.result[].createdAt | string | - |
| data.result[].status | boolean | - |
| data.result[].price | integer | - |
| data.total | integer | - |
| error | null | - |

#### 响应示例

```json
{
    "status": "success",
    "message": "Request succeeded",
    "data": {
        "result": [
            {
                "productId": "P000013",
                "productName": "三网快充100",
                "brandId": {
                    "_id": "67c0925f6d0f572ad8442675",
                    "brandId": "1",
                    "brandName": "话费"
                },
                "cateId": [
                    {
                        "_id": "67c0925f6d0f572ad8442670",
                        "cateId": "1",
                        "cateName": "❤️三网快充"
                    }
                ],
                "premiumPercentage": 0,
                "updatedAt": "2025-04-08T07:49:52.646Z",
                "pType": "快充",
                "faceValue": 100,
                "createdAt": "2025-04-08T06:40:36.007Z",
                "status": true,
                "price": 95
            },
            {
                "productId": "P000014",
                "productName": "测试产品三网",
                "brandId": {
                    "_id": "67c0925f6d0f572ad8442675",
                    "brandId": "1",
                    "brandName": "话费"
                },
                "cateId": [
                    {
                        "_id": "67c0925f6d0f572ad8442670",
                        "cateId": "1",
                        "cateName": "❤️三网快充"
                    }
                ],
                "premiumPercentage": 0,
                "updatedAt": "2025-04-08T07:56:04.698Z",
                "pType": "快充",
                "faceValue": 100,
                "createdAt": "2025-04-08T07:56:04.698Z",
                "status": true,
                "price": 95
            }
        ],
        "total": 2
    },
    "error": null
}
```

---

### 3. 下单

- **请求方式**：`POST`
- **接口路径**：`/integration/v1/checkout`
- **接口分组**：`intrgration`

#### 接口说明

```js
{
    "accessKey": "c50dc8a657d3bfa7a76786983a71dc0c",  
    "productId": "P000014", // 套餐ID  后台查看或者调用产品信息接口
    "phone": "15186817855", // 充值号码
    "externalOrderNo"："Trobe123456" // 接入方订单号码
    "notificationUrl":"https:www.trobe.com" // 回调地址 具体查看回调详情
    "sign": "88E730FE302E4A9C38CFBC04973C8E55" // MD5大写签名 详见接口说明
}
```

#### 请求参数（Body，`application/json`）

| 参数名 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| accessKey | string | 是 | 用户通行key，用户后台获取 |
| productId | string | 是 | 套餐产品ID |
| externalOrderNo | string | 是 | 接入方订单号 |
| phone | string | 是 | 充值号码 |
| notificationUrl | string | 否 | 回调通知地址，详见回调通知说明 |
| sign | string | 是 | 签名，详见签名说明 |

#### 响应参数

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| status | string | - |
| message | string | - |
| data | object | - |
| data.orderNo | string | 系统订单号 |
| data.orderStatus | string | 订单状态：Created: 已创建 订单创建后分配的初始状态，订单准备处理。Processing: 已发起 系统已接受订单，正在处理中。订单向供应商发起 Succeeded: 成功 订单成功完成，所有操作或交易正常执行。Failed: 失败 订单处理时遇到错误，未能成功完成。Cancel: 取消订单 订单处理时遇到错误，未能成功完成。 |
| data.rechargeStatus | integer | 充值状态：-1: 取消；0: 充值中；1: 充值成功；2: 充值失败 （-1和2都为失败）；3: 部分成功；4: 未充值 |
| data.externalOrderNo | string | 接入方订单号 |
| data.totalAmount | string | 订单金额 |
| data.productId | string | 套餐ID |
| error | null | - |

#### 成功示例

```json
{
    "status": "success",
    "message": "Request succeeded",
    "data": {
        "orderNo": "TD202504231020055819",
        "orderStatus": "Processing",
        "rechargeStatus": 0,
        "totalAmount": "20.00",
        "productId": "P0001",
        "externalOrderNo": "TR900000001"
    },
    "error": null
}
```

#### 异常示例

```json
{
    "status": "error",
    "message": "REQUEST_EXPIRED",
    "data": null,
    "error": {
        "code": "REQUEST_EXPIRED",
        "details": "请求过期"
    }
}
```

---

### 4. 订单查询

- **请求方式**：`POST`
- **接口路径**：`/integration/v1/orders`
- **接口分组**：`intrgration`

#### 接口说明

**订单状态说明 orderStatus**

- Created: 已创建 订单创建后分配的初始状态，订单准备处理。
- Processing: 已发起 系统已接受订单，正在处理中。订单向供应商发起
- Succeeded: 成功 订单成功完成，所有操作或交易正常执行。
- Failed: 失败 订单处理时遇到错误，未能成功完成。
- Cancel: 取消订单 订单处理时遇到错误，未能成功完成。

**充值状态说明 rechargeStatus**

- -1: 取消
- 0: 充值中
- 1: 充值成功
- 2: 充值失败 （-1和2都为失败）
- 3: 部分成功
- 4: 未充值

#### 请求参数（Body，`application/json`）

| 参数名 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| accessKey | string | 是 | 用户通行Key，用户后台获取 |
| orderNo | string | 是 | 接入方订单号或者系统订单号 |
| sign | string | 是 | 签名，详见签名说明 |

#### 响应参数

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| status | string | - |
| message | string | - |
| data | object | - |
| data.orderNo | string | 系统订单号 |
| data.externalOrderNo | string | 接入方订单号 |
| data.userInfo | object | 账号信息 |
| data.userInfo._id | string | - |
| data.userInfo.username | string | - |
| data.userInfo.nickname | string | - |
| data.userInfo.id | string | - |
| data.phone | string | 充值号码 |
| data.productInfo | object | 套餐产品信息 |
| data.productInfo._id | string | - |
| data.productInfo.productName | string | - |
| data.productInfo.faceValue | integer | - |
| data.productInfo.productId | string | - |
| data.productInfo.pType | string | - |
| data.brandInfo | object | 运营商或品牌信息 |
| data.brandInfo._id | string | - |
| data.brandInfo.brandId | string | - |
| data.brandInfo.brandName | string | - |
| data.totalAmount | string | 订单消费总金额 |
| data.orderType | string | 订单类型：话费、电费、其他 |
| data.ip | string | 下单IP |
| data.address | string | 下单地址 |
| data.area | string | 电费充值区域 |
| data.city | string | 电费充值城市 |
| data.rechargeStatus | integer | 充值状态：-1: 取消；0: 充值中；1: 充值成功；2: 充值失败 （-1和2都为失败）；3: 部分成功；4: 未充值 |
| data.orderStatus | string | 订单状态：Created: 已创建 订单创建后分配的初始状态，订单准备处理。Processing: 已发起 系统已接受订单，正在处理中。订单向供应商发起 Succeeded: 成功 订单成功完成，所有操作或交易正常执行。Failed: 失败 订单处理时遇到错误，未能成功完成。Cancel: 取消订单 订单处理时遇到错误，未能成功完成。 |
| data.certificate | string | 话费成功充值凭证 |
| data.createdAt | string | 订单创建时间 |
| error | null | 错误信息，提单失败 data 为 null，error 有具体信息 |

#### 响应示例

```json
{
    "status": "success",
    "message": "Request succeeded",
    "data": {
        "orderNo": "TD202504231020055819",
        "externalOrderNo": "TR900000001",
        "userInfo": {
            "_id": "67ac989286a3e59b08f51620",
            "username": "紫茄子",
            "nickname": "紫茄子",
            "id": "67ac989286a3e59b08f51620"
        },
        "phone": "17385318794",
        "productInfo": {
            "_id": "67fd0c3432860bcc9c111aa9",
            "productName": "三网-50",
            "faceValue": 50,
            "productId": "P000019",
            "pType": "快充"
        },
        "brandInfo": {
            "_id": "67c0925f6d0f572ad8442675",
            "brandId": "1",
            "brandName": "话费"
        },
        "totalAmount": "47.50",
        "orderType": "话费",
        "ip": "0.0.0.0",
        "address": "保留地址",
        "area": "-",
        "city": "-",
        "rechargeStatus": 0,
        "orderStatus": "Processing",
        "certificate": "",
        "createdAt": "2025-04-23T02:20:05.794Z"
    },
    "error": null
}
```

---

### 5. 号码识别运营商

- **请求方式**：`POST`
- **接口路径**：`/integration/v1/identifNumberAttr`
- **接口分组**：`intrgration`

#### 请求参数（Body，`application/json`）

| 参数名 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| accessKey | string | 是 | - |
| phone | string | 是 | - |
| sign | string | 是 | - |

#### 响应参数

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| status | string | - |
| message | string | - |
| data | object | - |
| data._id | string | - |
| data.pid | string | - |
| data.pref | string | - |
| data.phone | string | - |
| data.province | string | - |
| data.city | string | - |
| data.isp | string | - |
| data.isp_type | string | - |
| data.post_code | string | - |
| data.city_code | string | - |
| data.area_code | string | - |
| error | null | - |

#### 响应示例

```json
{
    "status": "success",
    "message": "Request succeeded",
    "data": {
        "_id": "67adc0a97e7b0000e504c56a",
        "pid": "301176",
        "pref": "173",
        "phone": "1738531",
        "province": "贵州",
        "city": "贵阳",
        "isp": "电信",
        "isp_type": "3",
        "post_code": "550000",
        "city_code": "0851",
        "area_code": "520100"
    },
    "error": null
}
```

---

### 6. 回调

- **请求方式**：`POST`
- **接口路径**：下单传入的回调地址
- **接口分组**：`intrgration`

#### 接口说明

> 可以返回任何值，充值成功和失败只回调一次，后面需要自行使用订单查询接口 直至更新到最新状态

订单状态说明 orderStatus（可以就这个状态去处理自己的逻辑）：

```text
Created: 已创建 订单创建后分配的初始状态，订单准备处理。
Processing: 已发起 系统已接受订单，正在处理中。订单向供应商发起
Succeeded: 成功 订单成功完成，所有操作或交易正常执行。
Failed: 失败 订单处理时遇到错误，未能成功完成。
Cancel: 取消订单 订单处理时遇到错误，未能成功完成。
```

充值状态说明 rechargeStatus：

```text
-1: 取消
0: 充值中
1: 充值成功
2: 充值失败 （-1和2都为失败）
3: 部分成功
4: 未充值
```

#### 回调请求参数（Body，`application/json`）

| 参数名 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| orderNo | string | 是 | 系统订单号码 |
| isp | string | 是 | - |
| area | string | 是 | - |
| city | string | 是 | - |
| orderType | string | 是 | - |
| userId | string | 是 | - |
| notificationUrl | string | 是 | - |
| ip | string | 是 | - |
| phone | string | 是 | - |
| productId | string | 是 | - |
| certificate | string | 是 | - |
| totalAmount | string | 是 | - |
| rechargeStatus | integer | 是 | 充值状态 |
| externalOrderNo | string | 是 | 接入方订单号 |
| orderStatus | string | 是 | 订单状态 |

#### 响应

- **HTTP 状态码**：`200 成功`
- **响应体**：未定义字段

---

## 我们的对接方式（驱动 `turbo`）

- 接口参数：接口域名（`https://上游域名`，下单 `/integration/v1/checkout`、查单 `/integration/v1/orders`、余额 `/integration/v1/accounts`、商品列表 `/integration/v1/catalog` 写在驱动里）、`accessKey`、`secretKey`。
- 回调地址自动生成（`回调域名/notify/supplier/{供应商编码}`），每单随下单作为 `notificationUrl` 传过去；没配回调域名时不传。
- 只接话费。字段对应：`externalOrderNo` = 提交单号 `attempt_no`，`phone` = 手机号，`productId` = 供应商商品编码（上游套餐 ID，可从商品列表里选）。上游返回的 `orderNo` 存为供应商订单号；查单的 `orderNo` 传提交单号（文档说接入方订单号或系统订单号都行）。
- 全部接口 POST JSON。签名按第二节：去掉 `sign`，按参数名升序拼 `k=v&...`（值不做 URL 编码），末尾拼 `&secretKey=`，md5 转大写。空值参数不发（第五节说空值会导致签名无效）。
- **回调没有签名，不能信**：只取 `externalOrderNo`，再主动查单，以查单结果为准，回 `success`；查单出错或没拿到明确结果回 `fail`（HTTP 400），定时查单照常继续。上游说成功 / 失败只回调一次，所以结果主要靠定时查单兜底。
- 余额取 `data.balance`。商品列表取 `data.result`：`productId` 作编码、`price` 作进价、`faceValue` 作面值；`status=false` 算下架；文档没有运营商字段，只有 `brandName` 里带运营商名（移动 / 联通 / 电信 / 广电）时才认，否则留空；分类名和 `pType` 作为补充说明。

| 情况 | 结果 |
|---|---|
| 下单 `status=success` | 处理中，等回调 / 查单（`data.orderStatus` 已是终态时按下面查单的规则判） |
| 下单 `status` 不是 `success`（如 `error`），不论 HTTP 状态码 | 失败，换下一个供应商（上游有返回、不是成功就判失败） |
| 下单网络超时、连不上、返回无法解析或没有 `status`（没有拿到返回值） | 立即转异常订单并标为人工处理单：查单结果只记录，回调明确成功 / 失败时交回正常流程（见 docs/project.md 2.5） |
| 查单 `orderStatus=Succeeded` | 成功 |
| 查单 `orderStatus=Failed` / `Cancel` | 失败 |
| 查单 `orderStatus=Created` / `Processing` 或其他 | 处理中 |
| 查单 `rechargeStatus=3` 部分成功（不论 `orderStatus`） | 处理中，不判结果，留给人工核实 |
| 查单 `status=error`、没有 `orderStatus`、返回的 `externalOrderNo` 对不上、没拿到返回 | 处理中，只记录，订单状态不动；一直没结果的到时转异常人工处理 |
| 回调 | 不看回调内容，按查单结果处理 |

## 待和上游确认

1. 回调能不能带签名（现在只能靠查单确认）。
2. 同一 `externalOrderNo` 重复下单会返回什么、会不会重复扣款。
3. `rechargeStatus=3` 部分成功实际会不会出现、出现时上游扣多少钱。
4. 商品列表能否给出运营商字段。
