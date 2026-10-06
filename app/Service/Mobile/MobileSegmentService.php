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

namespace App\Service\Mobile;

use App\Dao\MobileSegmentDao;
use App\Model\MobileSegment;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 按号段库识别手机号的运营商和省份，规则见 docs/project.md 2.3。
 */
class MobileSegmentService extends AbstractService
{
    #[Inject]
    protected MobileSegmentDao $mobileSegmentDao;

    /**
     * 只支持中国大陆 11 位手机号。
     */
    public function isValidMobile(string $mobile): bool
    {
        return preg_match('/^1\d{10}$/', $mobile) === 1;
    }

    /**
     * 号段库里没有这个号段时返回 null。是不是虚拟运营商号段由调用方看 is_virtual 决定怎么处理。
     */
    public function identify(string $mobile): ?MobileSegment
    {
        if (! $this->isValidMobile($mobile)) {
            return null;
        }

        return $this->mobileSegmentDao->findBySegment(substr($mobile, 0, 7));
    }
}
