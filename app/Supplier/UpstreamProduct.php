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
 * 上游商品列表里的一项，后台建供应商商品时给管理员挑选、预填表单。上游没给的字段留空，不猜。
 */
final class UpstreamProduct
{
    /**
     * @param list<string> $operators 运营商编码（App\Enum\Operator）
     */
    public function __construct(
        /** 下单时传给上游的商品编码，即供应商商品编码 */
        public readonly string $code,
        public readonly string $name,
        /** 我们的进价（元） */
        public readonly ?string $price = null,
        public readonly ?int $faceValue = null,
        public readonly array $operators = [],
        public readonly bool $onSale = true,
        /** 补充说明，比如限定省份、到账速度 */
        public readonly ?string $note = null,
    ) {
    }

    /**
     * @return array{code: string, name: string, price: null|string, face_value: null|int, operators: list<string>, on_sale: bool, note: null|string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'price' => $this->price,
            'face_value' => $this->faceValue,
            'operators' => $this->operators,
            'on_sale' => $this->onSale,
            'note' => $this->note,
        ];
    }
}
