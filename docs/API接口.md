# 全局公共参数

**全局Header参数**

| 参数名 | 示例值 | 参数类型 | 是否必填 | 参数描述 |
| --- | --- | ---- | ---- | ---- |
| Userid | - | string | 是 | 用户ID |
| Sign | - | string | 是 | 签名 |
| Timestamp | - | string | 是 | 时间戳 |

**全局Query参数**

| 参数名 | 示例值 | 参数类型 | 是否必填 | 参数描述 |
| --- | --- | ---- | ---- | ---- |
| 暂无参数 |

**全局Body参数**

| 参数名 | 示例值 | 参数类型 | 是否必填 | 参数描述 |
| --- | --- | ---- | ---- | ---- |
| 暂无参数 |

**全局认证方式**

> 无需认证

# 状态码说明

| 状态码 | 中文描述 |
| --- | ---- |
| 200 | 成功 |
| 411 | 失败 |

# 签名生成

> 创建人: 商腾科技

> 更新人: 商腾科技

> 创建时间: 2025-10-10 16:21:42

> 更新时间: 2025-10-23 17:50:01

```
/***********************************************************
 * 签名算法： 
 * 1、将POST参数按照ASCII顺序从小到大排序，
 * 2、将排序后的参数转换为json字符串，特殊符号不转义，保持中文编码
 * 3、依照顺序： 【秒级时间戳】+【json字符串】+【ApiKey】拼接起来
 * 4、对拼接后的字符串进行sha1加密
 ***********************************************************/
function makeSign($post = [])
{
    if ($post) {
        //按照ASCII顺序从小到大排序post参数
        ksort($post); 
        //将数据转为json字符串，特殊符号不转义，保持中文编码
        $post = json_encode($post , JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    } else {
        //空数据只需要传递{}
        $post = "{}";
    }
    $time = time();
    //用POST方法，json格式传递，编码是utf-8
    $header[] = "Content-Type: application/json; charset=utf-8";
    $header[] = "Sign: " . sha1($time . $post . $this->apikey);
    $header[] = "Timestamp: " . $time;
    $header[] = "Userid: " . $this->mchid;
    return [$post, $header];
}
```

**Query**

# 回调数据

> 创建人: 商腾科技

> 更新人: 商腾科技

> 创建时间: 2025-10-10 16:35:29

> 更新时间: 2025-10-23 18:46:01

**数据实例：,:::highlight yellow 💡**

```php
//json格式的数据，收到返回：'ok'
{
    "external_orderno":"API123123123xxxxxx", //外部订单号  
    "orderno":"API230923483295",             //本站订单号
    "status": 2,   //状态:1=正在处理,2=已完成,3=取消交易,4=已退款,5=已失败
    "msg": ""      //已失败的状态反馈报错原因
}
```

**:::**

**Query**

# 用户列表-查看余额

> 创建人: 商腾科技

> 更新人: 商腾科技

> 创建时间: 2025-07-31 17:41:12

> 更新时间: 2025-10-23 17:46:21

```text
暂无描述
```

**接口状态**

> 已完成

**接口URL**

> /api/User/balance

**请求方式**

> POST

**Content-Type**

> urlencoded

**认证方式**

> 无需认证

**响应示例**

* 成功(200)

```javascript
{"status":200,"data":{}}
```

| 参数名 | 示例值 | 参数类型 | 参数描述 |
| --- | --- | ---- | ---- |
| phone_balance | - | number | 话费余额 |
| power_balance | - | number | 电费余额 |
| member_balance | - | number | 会员余额 |

**Query**

# 商品分类-数据列表

> 创建人: 商腾科技

> 更新人: 商腾科技

> 创建时间: 2025-07-31 17:15:49

> 更新时间: 2025-10-10 16:25:53

```text
暂无描述
```

**接口状态**

> 已完成

**接口URL**

> /api/Productcate/index

**请求方式**

> POST

**Content-Type**

> none

**认证方式**

> 无需认证

**响应示例**

* 成功(200)

```javascript
{
    "status": 200,
    "data": {
        
    }
}
```

| 参数名 | 示例值 | 参数类型 | 参数描述 |
| --- | --- | ---- | ---- |
| cate_id | - | number | 编号 |
| cate_name | - | string | 名称 |
| status | - | number | 状态 , 正常-1 ; 禁用-0 ; |
| sort | - | number | 排序 |

**Query**

# 商品类型-数据列表

> 创建人: 商腾科技

> 更新人: 商腾科技

> 创建时间: 2025-07-31 20:50:47

> 更新时间: 2025-10-23 17:47:53

```text
暂无描述
```

**接口状态**

> 已完成

**接口URL**

> /api/Producttype/index

**请求方式**

> POST

**Content-Type**

> urlencoded

**请求Body参数**

| 参数名 | 示例值 | 参数类型 | 是否必填 | 参数描述 |
| --- | --- | ---- | ---- | ---- |
| cate_id | - | number | 否 | 所属分类 |

**认证方式**

> 无需认证

**响应示例**

* 成功(200)

```javascript
{
    "status": 200,
    "data": []
}
```

| 参数名 | 示例值 | 参数类型 | 参数描述 |
| --- | --- | ---- | ---- |
| type_id | - | number | 编号 |
| cate_name | - | string | 所属分类 |
| type_name | - | string | 名称 |
| status | - | number | 状态 |
| sort | - | number | 排序 |

**Query**

# 商品列表-数据列表

> 创建人: 商腾科技

> 更新人: 商腾科技

> 创建时间: 2025-07-31 23:17:41

> 更新时间: 2025-10-10 16:26:04

```text
暂无描述
```

**接口状态**

> 已完成

**接口URL**

> /api/Product/index

**请求方式**

> POST

**Content-Type**

> urlencoded

**请求Body参数**

| 参数名 | 示例值 | 参数类型 | 是否必填 | 参数描述 |
| --- | --- | ---- | ---- | ---- |
| limit | 20 | number | 是 | 每页显示条数，默认为20 |
| page | 1 | number | 是 | 页码，默认为1 |
| cate_id | - | number | 否 | 分类ID |
| type_id | - | number | 否 | 类型ID |

**认证方式**

> 无需认证

**响应示例**

* 成功(200)

```javascript
{
    "status": 200,
    "data": [{
        "total": 10,
        "per_page": 20,
        "current_page": 1,
        "last_page": 1,
        "data": []
    }]
}
```

| 参数名 | 示例值 | 参数类型 | 参数描述 |
| --- | --- | ---- | ---- |
| id | - | number | 编号 |
| name | - | string | 名称 |
| price | - | number | 价格 |
| cate_name | - | string | 分类 |
| type_name | - | string | 类型 |
| product_name | - | string | 供货商品 |
| isp | - | string | 运营商 , 移动-1 ; 联通-2 ; 电信-3 ; 虚拟-4 ; |
| remark | - | string | 备注 |
| margin | - | string | 保证金 |
| success_rate | - | string | 成功率 |
| time_cost | - | string | 平均耗时 |
| api_open | - | number | 开启API充值 , 开启-1 ; 关闭-0 ; |
| timeout_auto_refund | - | number | 超时自动退单 , 开启-1 ; 关闭-0 ; |
| status | - | number | 状态 , 上架-1 ; 下架-0 ; |
| sort | - | number | 排序 |
| create_time | - | number | 创建时间 |

**Query**

# 商品列表-商品详情

> 创建人: 商腾科技

> 更新人: 商腾科技

> 创建时间: 2025-07-31 23:17:41

> 更新时间: 2025-10-10 16:26:32

```text
暂无描述
```

**接口状态**

> 已完成

**接口URL**

> /api/Product/detail

**请求方式**

> POST

**Content-Type**

> urlencoded

**请求Body参数**

| 参数名 | 示例值 | 参数类型 | 是否必填 | 参数描述 |
| --- | --- | ---- | ---- | ---- |
| id | 1 | number | 是 | 编号 |

**认证方式**

> 无需认证

**响应示例**

* 成功(200)

```javascript
{
    "status": 200,
    "data": {
        
    }
}
```

| 参数名 | 示例值 | 参数类型 | 参数描述 |
| --- | --- | ---- | ---- |
| name | - | string | 名称 |
| tip | - | string | 描述 |
| price | - | number | 价格 |
| cate_id | - | number | 分类ID |
| type_id | - | number | 类型ID |
| margin | - | string | 保证金 |
| success_rate | - | string | 成功率 |
| time_cost | - | string | 平均耗时 |

**Query**

# 充值订单-创建订单

> 创建人: 商腾科技

> 更新人: 商腾科技

> 创建时间: 2025-07-31 17:38:34

> 更新时间: 2025-12-16 00:21:03

```text
暂无描述
```

**接口状态**

> 已完成

**接口URL**

> /api/Order/create

**请求方式**

> POST

**Content-Type**

> urlencoded

**请求Body参数**

| 参数名 | 示例值 | 参数类型 | 是否必填 | 参数描述 |
| --- | --- | ---- | ---- | ---- |
| external_orderno | API123123123123 | string | 是 | 经销商订单号 |
| product_id | 1 | string | 是 | 商品ID |
| recharge_account | 18125295211 | string | 是 | 充值账户 |
| notify_url | https://abc.com/api/notify | string | 是 | 回调地址 |
| product_price | 99.8 | string | 否 | 产品价格(传递后校验价格) |
| face_value | 100 | string | 否 | 面值(传递后校验面值50/100/200/300/500) |

**认证方式**

> 无需认证

**响应示例**

* 成功(200)

```javascript
{
    "status": 200,
    "msg": "提交成功"
}
```

**Query**

# 充值订单-查询订单

> 创建人: 商腾科技

> 更新人: 商腾科技

> 创建时间: 2025-07-31 17:38:35

> 更新时间: 2025-10-14 21:15:47

```text
暂无描述
```

**接口状态**

> 已完成

**接口URL**

> /api/Order/query

**请求方式**

> POST

**Content-Type**

> urlencoded

**请求Body参数**

| 参数名 | 示例值 | 参数类型 | 是否必填 | 参数描述 |
| --- | --- | ---- | ---- | ---- |
| external_orderno | - | string | 是 | 经销商订单号 |

**认证方式**

> 无需认证

**响应示例**

* 成功(200)

```javascript
{
    "status": 200,
    "data": {
        "external_orderno": "API123123123xxxxxx", 
        "orderno": "API230923483295", 
        "status": 2 
    }
}
```

| 参数名 | 示例值 | 参数类型 | 参数描述 |
| --- | --- | ---- | ---- |
| product_id | - | string | 商品ID |
| recharge_account | - | string | 充值账户 |
| status | - | number | 状态 ,  0：待充值 ; 1：充值中 ; 2：已充值 ;  3：已失败 ;  4：已退款 ; 5：已失败 |
| create_time | - | number | 提交时间 |
| amount | - | string | 金额 |
| commission | - | string | 佣金 |
| provider_id | - | string | 所属货源 |
| cate_id | - | string | 分类ID |
| type_id | - | string | 类型ID |
| providergood_id | - | string | 供货商品ID |
| isp | - | number | 运营商 , 移动-1 ; 联通-2 ; 电信-3 ; 虚拟-4 ; |
| end_time | - | number | 完成时间 |
| notify_url | - | string | 回调地址 |

**Query**

# 充值订单-取消订单

> 创建人: 商腾科技

> 更新人: 商腾科技

> 创建时间: 2025-07-31 17:38:35

> 更新时间: 2025-10-10 16:31:59

```text
暂无描述
```

**接口状态**

> 已完成

**接口URL**

> /api/Order/close

**请求方式**

> POST

**Content-Type**

> urlencoded

**请求Body参数**

| 参数名 | 示例值 | 参数类型 | 是否必填 | 参数描述 |
| --- | --- | ---- | ---- | ---- |
| external_orderno | - | string | 是 | 经销商订单号 |

**认证方式**

> 无需认证

**响应示例**

* 成功(200)

```javascript
{
    "status": 200,
    "msg": "取消成功"
}
```

**Query**
