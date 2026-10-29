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

namespace App\Service\Order;

use App\Dao\MobileSegmentDao;
use App\Dao\OrderDao;
use App\Enum\City;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 给历史订单补上市：按手机号前 7 位查号段库，城市统一写法后写到订单上。号段库里查不到的留空。
 */
class OrderCityBackfillService extends AbstractService
{
    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected MobileSegmentDao $mobileSegmentDao;

    /**
     * @param null|callable(int, int): void $progress 每批处理完回调（这批最后的订单 id，累计补上的数量）
     * @return array{filled: int, missing: int} 补上的、号段库查不到的
     */
    public function backfill(int $batch = 2000, ?callable $progress = null): array
    {
        $afterId = 0;
        $filled = 0;
        $missing = 0;
        while (($mobiles = $this->orderDao->withoutCityAfter($afterId, $batch)) !== []) {
            $cities = $this->mobileSegmentDao->citiesFor(array_values(array_unique(array_map(static fn (string $m) => substr($m, 0, 7), $mobiles))));
            $byCity = [];
            foreach ($mobiles as $id => $mobile) {
                $city = City::normalize($cities[substr($mobile, 0, 7)] ?? null);
                if ($city === null) {
                    ++$missing;
                    continue;
                }
                $byCity[$city][] = (int) $id;
            }
            foreach ($byCity as $city => $ids) {
                $this->orderDao->setCity($ids, (string) $city);
                $filled += count($ids);
            }
            $afterId = (int) array_key_last($mobiles);
            if ($progress !== null) {
                $progress($afterId, $filled);
            }
        }

        return ['filled' => $filled, 'missing' => $missing];
    }
}
