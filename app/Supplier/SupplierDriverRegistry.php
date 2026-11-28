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

use App\Supplier\Driver\ChongyidaDriver;
use App\Supplier\Driver\DayuanrenDriver;
use App\Supplier\Driver\JideshengDriver;
use App\Supplier\Driver\KasushouDriver;
use App\Supplier\Driver\LinxianDriver;
use App\Supplier\Driver\LiuhaoDriver;
use App\Supplier\Driver\MockDriver;
use App\Supplier\Driver\OpenPlatformDriver;
use App\Supplier\Driver\PikoudaiDriver;
use App\Supplier\Driver\QylmDriver;
use App\Supplier\Driver\ShangtengDriver;
use App\Supplier\Driver\TurboDriver;
use App\Supplier\Driver\XuanjieDriver;
use App\Supplier\Driver\YbDriver;
use App\Supplier\Driver\YingyetingDriver;
use App\Supplier\Driver\ZhixinDriver;
use App\Supplier\Driver\ZhongkongDriver;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

/**
 * 已登记的供应商驱动，按 suppliers.driver 找到对应的实现。
 *
 * 模拟驱动 MockDriver 只在 `SUPPLIER_MOCK_ENABLED=true`（config supplier.mock_enabled）时可用：关着时后台选不到、
 * 建不了；已有的 mock 供应商提交时按「调用异常」处理（处理中、转人工），不会被当成充值成功。
 */
class SupplierDriverRegistry
{
    /**
     * 新接一家供应商：写好驱动类后加到这里。
     *
     * @var list<class-string<SupplierDriverInterface>>
     */
    public const DRIVERS = [
        MockDriver::class,
        OpenPlatformDriver::class,
        ShangtengDriver::class,
        ZhongkongDriver::class,
        DayuanrenDriver::class,
        PikoudaiDriver::class,
        LiuhaoDriver::class,
        YingyetingDriver::class,
        ChongyidaDriver::class,
        XuanjieDriver::class,
        ZhixinDriver::class,
        LinxianDriver::class,
        TurboDriver::class,
        QylmDriver::class,
        YbDriver::class,
        JideshengDriver::class,
        KasushouDriver::class,
    ];

    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * @return list<SupplierDriverInterface>
     */
    public function all(): array
    {
        $mockEnabled = (bool) $this->container->get(ConfigInterface::class)->get('supplier.mock_enabled', false);
        $classes = array_values(array_filter(self::DRIVERS, static fn (string $class) => $mockEnabled || $class !== MockDriver::class));

        return array_map(fn (string $class) => $this->container->get($class), $classes);
    }

    public function find(string $code): ?SupplierDriverInterface
    {
        foreach ($this->all() as $driver) {
            if ($driver->code() === $code) {
                return $driver;
            }
        }

        return null;
    }
}
