<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Supplier;

/**
 * 供应商提供了商品查询接口时，驱动额外实现这个接口，后台建供应商商品时就能从列表里选。
 */
interface ProvidesUpstreamProducts
{
    /**
     * 查上游可售商品。网络错误、上游返回出错时抛异常。
     *
     * @param array<string, string> $config 解密后的供应商接口参数
     * @return list<UpstreamProduct>
     */
    public function upstreamProducts(array $config): array;
}
