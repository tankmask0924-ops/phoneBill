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

namespace App\Service\Product;

use App\Dao\ChannelMaintenanceDao;
use App\Dao\ProductRouteDao;
use App\Service\AbstractService;
use Hyperf\Cache\Annotation\Cacheable;
use Hyperf\Cache\Annotation\CacheEvict;
use Hyperf\Di\Annotation\Inject;

/**
 * 选路：一个号码（运营商 + 省份）在平台商品下可以走哪些供应商商品，规则见 docs/project.md 2.1。
 * 后台号段查询页、下单、提交供应商都用这里，保证看到的结果一致。
 *
 * 缓存分两部分：
 * - 配置（绑定、供应商商品、运营商、覆盖省份、启停）按「商品 + 运营商 + 省份」缓存 1 小时，
 *   平台商品 / 供应商商品 / 供应商在后台改动、事务提交后调 flushConfig() 整体清掉；
 * - 通道维护按时间生效，单独缓存「还没结束的维护」，每次用当前时间在内存里判断，
 *   到点自动生效、自动恢复；新增或提前结束维护后调 flushMaintenances()。
 */
class ProductRouteService extends AbstractService
{
    #[Inject]
    protected ProductRouteDao $productRouteDao;

    #[Inject]
    protected ChannelMaintenanceDao $maintenanceDao;

    /**
     * @param list<int> $productIds
     * @return array<int, list<array<string, mixed>>> product_id => 候选列表，已按尝试顺序排好
     */
    public function candidates(array $productIds, string $operator, string $province): array
    {
        $now = date('Y-m-d H:i:s');
        $active = array_values(array_filter(
            $this->maintenances(),
            static fn (array $m) => $m['start_at'] <= $now && $m['end_at'] > $now
                && ($m['operator'] === null || $m['operator'] === $operator)
                && ($m['province'] === null || $m['province'] === $province)
        ));
        $blocked = array_flip(array_column($active, 'supplier_id'));

        $result = [];
        foreach ($productIds as $productId) {
            $result[$productId] = array_values(array_filter(
                $this->configuredCandidates($productId, $operator, $province),
                static fn (array $c) => ! isset($blocked[$c['supplier_id']])
            ));
        }

        return $result;
    }

    /**
     * 只看配置的候选（不看通道维护）。
     *
     * @return list<array<string, mixed>>
     */
    #[Cacheable(prefix: 'route_candidates', value: '#{productId}:#{operator}:#{province}', ttl: 3600)]
    public function configuredCandidates(int $productId, string $operator, string $province): array
    {
        $result = [];
        foreach ($this->productRouteDao->candidates($productId, $operator, $province) as $row) {
            $result[] = [
                'supplier_product_id' => (int) $row->supplier_product_id,
                'supplier_product_name' => $row->supplier_product_name,
                'external_code' => $row->external_code,
                'cost_price' => $row->cost_price,
                'priority' => (int) $row->priority,
                'supplier_id' => (int) $row->supplier_id,
                'supplier_name' => $row->supplier_name,
                'supplier_code' => $row->supplier_code,
                'supplier_driver' => $row->supplier_driver,
            ];
        }

        return $result;
    }

    /**
     * 还没结束的维护（含还没开始的），缓存 5 分钟；到点生效由 candidates() 按当前时间判断。
     *
     * @return list<array{supplier_id: int, operator: null|string, province: null|string, start_at: string, end_at: string}>
     */
    #[Cacheable(prefix: 'maintenance_windows', value: 'all', ttl: 300)]
    public function maintenances(): array
    {
        return $this->maintenanceDao->notEndedAt(date('Y-m-d H:i:s'));
    }

    #[CacheEvict(prefix: 'route_candidates', all: true)]
    public function flushConfig(): void
    {
    }

    #[CacheEvict(prefix: 'maintenance_windows', value: 'all')]
    public function flushMaintenances(): void
    {
    }
}
