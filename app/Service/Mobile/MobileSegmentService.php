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
use Hyperf\Cache\Annotation\Cacheable;
use Hyperf\Cache\Annotation\CacheEvict;
use Hyperf\Di\Annotation\Inject;

/**
 * 按号段库识别手机号的运营商和省份，规则见 docs/project.md 2.3。
 *
 * 号段每单都要查、几乎不变，按号段缓存 1 天（查不到的也缓存，防止乱填的号码一直打到数据库）。
 * MobileSegment 保存、删除时自动清掉对应号段（见 MobileSegment::saved()）；批量导入后调 flushAll()。
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
        $data = $this->lookup(substr($mobile, 0, 7));

        return $data === null ? null : new MobileSegment($data);
    }

    /**
     * @return null|array{segment: string, operator: string, province: string, city: null|string, is_virtual: bool}
     */
    #[Cacheable(prefix: 'segment', value: '#{segment}', ttl: 86400)]
    public function lookup(string $segment): ?array
    {
        $row = $this->mobileSegmentDao->findBySegment($segment);

        return $row === null ? null : [
            'segment' => $row->segment,
            'operator' => $row->operator,
            'province' => $row->province,
            'city' => $row->city,
            'is_virtual' => (bool) $row->is_virtual,
        ];
    }

    #[CacheEvict(prefix: 'segment', value: '#{segment}')]
    public function forget(string $segment): void
    {
    }

    /**
     * 号段库批量导入后调用。
     */
    #[CacheEvict(prefix: 'segment', all: true)]
    public function flushAll(): void
    {
    }
}
