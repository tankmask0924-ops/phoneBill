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

namespace App\Service\Admin;

use App\Dao\ProductDao;
use App\Enum\Operator;
use App\Model\Product;
use App\Service\AbstractService;
use App\Service\Mobile\MobileSegmentService;
use App\Service\Product\ProductRouteService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 风控 - 号段查询：输入手机号，看识别结果，以及每个上架商品会走哪些供应商商品（排查选路用）。
 */
class SegmentAdminService extends AbstractService
{
    #[Inject]
    protected MobileSegmentService $mobileSegmentService;

    #[Inject]
    protected ProductRouteService $productRouteService;

    #[Inject]
    protected ProductDao $productDao;

    /**
     * @return array<string, mixed>
     */
    public function lookup(mixed $mobile): array
    {
        $mobile = is_string($mobile) ? trim($mobile) : '';
        if (! $this->mobileSegmentService->isValidMobile($mobile)) {
            throw new HttpException(422, '请输入 11 位手机号');
        }

        $segment = $this->mobileSegmentService->identify($mobile);
        $result = [
            'mobile' => $mobile,
            'segment' => $segment === null ? null : [
                'segment' => $segment->segment,
                'operator' => $segment->operator,
                'operator_name' => Operator::tryFrom($segment->operator)?->label() ?? $segment->operator,
                'province' => $segment->province,
                'city' => $segment->city,
                'is_virtual' => $segment->is_virtual,
            ],
            'rejected_reason' => match (true) {
                $segment === null => '号段库里没有这个号段，下单会被拒绝',
                $segment->is_virtual => '虚拟运营商号码暂不支持，下单会被拒绝',
                default => null,
            },
            'products' => [],
        ];
        if ($result['rejected_reason'] !== null) {
            return $result;
        }

        $products = $this->productDao->newQuery()->where('status', 'active')->orderBy('face_value')->orderBy('id')->get();
        $candidates = $this->productRouteService->candidates($products->pluck('id')->all(), $segment->operator, $segment->province);
        $result['products'] = $products->map(static fn (Product $p) => [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'face_value' => $p->face_value,
            'candidates' => $candidates[$p->id] ?? [],
        ])->values()->all();

        return $result;
    }
}
