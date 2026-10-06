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

use App\Supplier\Driver\MockDriver;
use Psr\Container\ContainerInterface;

/**
 * 已登记的供应商驱动，按 suppliers.driver 找到对应的实现。
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
    ];

    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * @return list<SupplierDriverInterface>
     */
    public function all(): array
    {
        return array_map(fn (string $class) => $this->container->get($class), self::DRIVERS);
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
