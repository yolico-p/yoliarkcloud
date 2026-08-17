<?php

namespace App\Core;

class PerformanceMonitor
{
    private static $instance = null;
    private $metrics = [];
    private $startTime;
    private $memoryStart;
    private $dbQueries = [];
    private $cacheStats = [];

    private function __construct()
    {
        $this->startTime = microtime(true);
        $this->memoryStart = memory_get_usage(true);
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    public function startTimer($name)
    {
        $this->metrics[$name] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];
    }

    public function endTimer($name)
    {
        if (!isset($this->metrics[$name])) {
            return null;
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $this->metrics[$name]['end'] = $endTime;
        $this->metrics[$name]['memory_end'] = $endMemory;
        $this->metrics[$name]['duration'] = $endTime - $this->metrics[$name]['start'];
        $this->metrics[$name]['memory_used'] = $endMemory - $this->metrics[$name]['memory_start'];

        return $this->metrics[$name];
    }

    public function logQuery($sql, $params = [], $duration = 0)
    {
        $this->dbQueries[] = [
            'sql' => $sql,
            'params' => $params,
            'duration' => $duration,
            'time' => microtime(true),
        ];

        if (count($this->dbQueries) > 100) {
            array_shift($this->dbQueries);
        }
    }

    public function updateCacheStats($stats)
    {
        $this->cacheStats = $stats;
    }

    public function getSummary()
    {
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $totalTime = $endTime - $this->startTime;
        $totalMemory = $endMemory - $this->memoryStart;

        $slowQueries = array_filter($this->dbQueries, function($q) {
            return $q['duration'] > 0.1;
        });

        $queryStats = [
            'total' => count($this->dbQueries),
            'slow' => count($slowQueries),
            'avg_duration' => count($this->dbQueries) > 0
                ? array_sum(array_column($this->dbQueries, 'duration')) / count($this->dbQueries)
                : 0,
        ];

        return [
            'execution_time' => round($totalTime * 1000, 2) . 'ms',
            'memory_used' => Security::formatSize($totalMemory),
            'peak_memory' => Security::formatSize(memory_get_peak_usage(true)),
            'database' => $queryStats,
            'cache' => $this->cacheStats,
            'timers' => array_filter($this->metrics, function($m) {
                return isset($m['duration']);
            }),
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    public function getSlowQueries($threshold = 0.1)
    {
        return array_filter($this->dbQueries, function($q) use ($threshold) {
            return $q['duration'] > $threshold;
        });
    }

    public function renderDebugBar()
    {
        if (!defined('DEBUG') || !DEBUG) {
            return '';
        }

        $summary = $this->getSummary();

        return sprintf(
            '<div style="position:fixed;bottom:0;left:0;right:0;background:#222;color:#fff;padding:10px;font-family:monospace;font-size:12px;z-index:99999;">
                <div style="display:flex;justify-content:space-around;">
                    <span>⏱️ %s</span>
                    <span>💾 %s</span>
                    <span>🗄️ %d queries (%.2fms avg)</span>
                    <span>🐌 %d slow</span>
                    <span>🎯 Cache: %.2f%%</span>
                </div>
            </div>',
            $summary['execution_time'],
            $summary['memory_used'],
            $summary['database']['total'],
            $summary['database']['avg_duration'] * 1000,
            $summary['database']['slow'],
            isset($summary['cache']['hit_rate']) ? $summary['cache']['hit_rate'] : 0
        );
    }

    public function exportMetrics()
    {
        return json_encode($this->getSummary(), JSON_PRETTY_PRINT);
    }
}
