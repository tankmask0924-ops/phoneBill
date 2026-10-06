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

namespace App\Listener;

use Hyperf\Collection\Arr;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * SQL 日志（渠道 sql）。全量记录由 databases.{连接}.sql_log 控制，默认关：
 * 每单十几条查询，几十万单一天会写出几 GB；超过 slow_query_ms 的慢查询始终记 warning。
 */
#[Listener]
class DbQueryExecutedListener implements ListenerInterface
{
    /** 单条 SQL 最多记这么长，批量写入（如号段导入）的参数很长 */
    private const MAX_LENGTH = 2000;

    private LoggerInterface $logger;

    private ConfigInterface $config;

    public function __construct(ContainerInterface $container)
    {
        $this->logger = $container->get(LoggerFactory::class)->get('sql');
        $this->config = $container->get(ConfigInterface::class);
    }

    public function listen(): array
    {
        return [
            QueryExecuted::class,
        ];
    }

    /**
     * @param QueryExecuted $event
     */
    public function process(object $event): void
    {
        if (! $event instanceof QueryExecuted) {
            return;
        }
        $slowMs = (int) $this->config->get("databases.{$event->connectionName}.slow_query_ms", 500);
        $slow = $slowMs > 0 && $event->time >= $slowMs;
        if (! $slow && ! $this->config->get("databases.{$event->connectionName}.sql_log", false)) {
            return;
        }
        $message = sprintf('[%s] %s', $event->time, $this->interpolate($event->sql, $event->bindings));
        if (mb_strlen($message) > self::MAX_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_LENGTH) . '...（已截断）';
        }
        $slow ? $this->logger->warning('慢查询 ' . $message) : $this->logger->info($message);
    }

    /**
     * 把参数代入 SQL，只用于看日志。
     */
    private function interpolate(string $sql, array $bindings): string
    {
        if (Arr::isAssoc($bindings)) {
            return $sql;
        }
        $position = 0;
        foreach ($bindings as $value) {
            $position = strpos($sql, '?', $position);
            if ($position === false) {
                break;
            }
            $value = "'{$value}'";
            $sql = substr_replace($sql, $value, $position, 1);
            $position += strlen($value);
        }

        return $sql;
    }
}
