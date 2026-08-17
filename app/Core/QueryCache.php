<?php

namespace App\Core;

/**
 * QueryCache - 两级查询缓存。
 *
 * L1：进程内（$cache），三级 LRU + tag 依赖，命中零开销
 * L2：APCu 共享内存（如可用），跨 PHP-FPM worker 共享
 *
 * 跨进程 tag 失效采用「版本号」机制：每个 tag 在 APCu 中维护一个版本号，
 * clearByTags 时递增版本号；get 时比对缓存项中存储的版本号快照，
 * 不一致则视为失效。这样无需在 APCu 中维护 tag->keys 反向映射，
 * 也无需遍历删除。
 *
 * APCu 不可用时自动降级为纯 L1 缓存（无副作用）。
 */
class QueryCache
{
    private $cache = [];
    private $cacheTiers = ['hot' => [], 'warm' => [], 'cold' => []];
    private $cacheDependencies = [];
    private $cacheTTL = 600;
    private $cacheMaxSize = 500;
    private $hotCacheSize = 100;
    private $warmCacheSize = 200;
    private $cacheStats = ['hits' => 0, 'misses' => 0];
    private $enabled = true;

    /** APCu 是否可用（CLI 下 apcu_enabled 返回 false，自动降级） */
    private $apcuEnabled = false;
    /** APCu key 前缀，避免与其它应用冲突 */
    private $apcuPrefix = 'yac:qc:';
    /** data key 前缀 */
    private $apcuDataPrefix;
    /** tag 版本号 key 前缀 */
    private $apcuVerPrefix;

    public function __construct()
    {
        $this->apcuEnabled = extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled();
        $this->apcuDataPrefix = $this->apcuPrefix . 'd:';
        $this->apcuVerPrefix = $this->apcuPrefix . 'v:';
    }

    public function get($key)
    {
        if (!$this->enabled) {
            $this->cacheStats['misses']++;
            return null;
        }

        // L1：进程内
        if (isset($this->cache[$key])) {
            $entry = $this->cache[$key];
            if (time() - $entry['time'] >= $this->cacheTTL) {
                $this->remove($key);
            } else {
                $this->moveToHot($key);
                $this->cacheStats['hits']++;
                return $entry['data'];
            }
        }

        // L2：APCu（跨进程共享）
        if ($this->apcuEnabled) {
            $apcuEntry = apcu_fetch($this->apcuDataPrefix . $key, $ok);
            if ($ok && is_array($apcuEntry)) {
                if ((time() - $apcuEntry['time']) >= $this->cacheTTL
                    || !$this->validateTagVersions($apcuEntry['tag_versions'] ?? [])) {
                    apcu_delete($this->apcuDataPrefix . $key);
                } else {
                    // 回填 L1，下次同进程直接命中 L1
                    $this->cache[$key] = [
                        'data' => $apcuEntry['data'],
                        'time' => $apcuEntry['time'],
                        'tags' => $apcuEntry['tags'],
                        'hits' => 0,
                    ];
                    $this->addToCacheTier($key);
                    $this->enforceCacheSize();
                    if (!empty($apcuEntry['tags'])) {
                        foreach ($apcuEntry['tags'] as $tag) {
                            $this->cacheDependencies[$tag][] = $key;
                        }
                    }
                    $this->cacheStats['hits']++;
                    return $apcuEntry['data'];
                }
            }
        }

        $this->cacheStats['misses']++;
        return null;
    }

    public function set($key, $data, $tags = [])
    {
        $now = time();
        $this->cache[$key] = [
            'data' => $data,
            'time' => $now,
            'tags' => $tags,
            'hits' => 0,
        ];

        if (!empty($tags)) {
            foreach ($tags as $tag) {
                $this->cacheDependencies[$tag][] = $key;
            }
        }

        $this->addToCacheTier($key);
        $this->enforceCacheSize();

        // 同步写入 APCu（含 tag 版本号快照）
        if ($this->apcuEnabled) {
            $tagVersions = [];
            foreach ($tags as $tag) {
                $tagVersions[$tag] = $this->getTagVersion($tag);
            }
            apcu_store(
                $this->apcuDataPrefix . $key,
                [
                    'data' => $data,
                    'time' => $now,
                    'tags' => $tags,
                    'tag_versions' => $tagVersions,
                ],
                $this->cacheTTL
            );
        }
    }

    public function clear($pattern = null)
    {
        if ($pattern === null) {
            $this->cache = [];
            $this->cacheDependencies = [];
            $this->cacheTiers = ['hot' => [], 'warm' => [], 'cold' => []];
            // APCu 无法高效批量删除，但全清场景罕见；通过递增所有已知 tag 版本号
            // 让残留项自然失效。这里不主动遍历 APCu（开销大），依赖 TTL 兜底。
            if ($this->apcuEnabled && class_exists('APCUIterator', false)) {
                $iter = new \APCUIterator('/^' . preg_quote($this->apcuDataPrefix, '/') . '/');
                apcu_delete($iter);
            }
        } else {
            foreach ($this->cache as $key => $value) {
                if (strpos($key, $pattern) !== false) {
                    $this->removeFromCacheTiers($key);
                    unset($this->cache[$key]);
                    if ($this->apcuEnabled) {
                        apcu_delete($this->apcuDataPrefix . $key);
                    }
                }
            }
        }
    }

    public function clearByTags($tags)
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $keysToRemove = [];

        foreach ($tags as $tag) {
            if (isset($this->cacheDependencies[$tag])) {
                foreach ($this->cacheDependencies[$tag] as $key) {
                    $keysToRemove[$key] = true;
                }
                unset($this->cacheDependencies[$tag]);
            }
            // 跨进程失效：递增 tag 版本号，其它进程的缓存项下次 get 时检测到版本不一致即失效
            if ($this->apcuEnabled) {
                $this->bumpTagVersion($tag);
            }
        }

        foreach (array_keys($keysToRemove) as $key) {
            $this->remove($key);
            if ($this->apcuEnabled) {
                apcu_delete($this->apcuDataPrefix . $key);
            }
        }
    }

    /**
     * 读取 tag 当前版本号。不存在则初始化为 0 并返回。
     */
    private function getTagVersion(string $tag): int
    {
        if (!$this->apcuEnabled) return 0;
        $ver = apcu_fetch($this->apcuVerPrefix . $tag, $ok);
        if (!$ok) {
            apcu_store($this->apcuVerPrefix . $tag, 0);
            return 0;
        }
        return (int)$ver;
    }

    /**
     * 递增 tag 版本号（失效信号）。apcu_inc 对不存在的 key 会失败，需 fallback。
     */
    private function bumpTagVersion(string $tag): void
    {
        $key = $this->apcuVerPrefix . $tag;
        $result = apcu_inc($key, 1, $ok);
        if (!$ok) {
            apcu_store($key, 1);
        }
    }

    /**
     * 校验缓存项中存储的 tag 版本号快照是否仍与当前版本号一致。
     * 任一 tag 版本号不匹配 → 缓存项已失效。
     */
    private function validateTagVersions(array $tagVersions): bool
    {
        foreach ($tagVersions as $tag => $savedVer) {
            if ($this->getTagVersion($tag) !== (int)$savedVer) {
                return false;
            }
        }
        return true;
    }

    public function getStats()
    {
        return $this->cacheStats;
    }

    public function getInfo()
    {
        return [
            'stats' => $this->cacheStats,
            'hit_rate' => ($this->cacheStats['hits'] + $this->cacheStats['misses']) > 0
                ? round($this->cacheStats['hits'] / ($this->cacheStats['hits'] + $this->cacheStats['misses']) * 100, 2)
                : 0,
            'size' => count($this->cache),
            'max_size' => $this->cacheMaxSize,
            'tiers' => [
                'hot' => count($this->cacheTiers['hot']),
                'warm' => count($this->cacheTiers['warm']),
                'cold' => count($this->cacheTiers['cold']),
            ],
            'dependencies' => count($this->cacheDependencies),
        ];
    }

    public function setEnabled($enabled)
    {
        $this->enabled = $enabled;
    }

    public function isEnabled()
    {
        return $this->enabled;
    }

    private function remove($key)
    {
        $this->removeFromCacheTiers($key);
        unset($this->cache[$key]);
        if ($this->apcuEnabled) {
            apcu_delete($this->apcuDataPrefix . $key);
        }
    }

    private function addToCacheTier($key)
    {
        $this->removeFromCacheTiers($key);
        $this->cacheTiers['hot'][] = $key;

        if (count($this->cacheTiers['hot']) > $this->hotCacheSize) {
            $coldestKey = array_shift($this->cacheTiers['hot']);
            $this->cacheTiers['warm'][] = $coldestKey;

            if (count($this->cacheTiers['warm']) > $this->warmCacheSize) {
                $coldestKey = array_shift($this->cacheTiers['warm']);
                $this->cacheTiers['cold'][] = $coldestKey;

                if (count($this->cacheTiers['cold']) > ($this->cacheMaxSize - $this->hotCacheSize - $this->warmCacheSize)) {
                    $evictKey = array_shift($this->cacheTiers['cold']);
                    unset($this->cache[$evictKey]);
                }
            }
        }
    }

    private function removeFromCacheTiers($key)
    {
        foreach (['hot', 'warm', 'cold'] as $tier) {
            $index = array_search($key, $this->cacheTiers[$tier]);
            if ($index !== false) {
                unset($this->cacheTiers[$tier][$index]);
                $this->cacheTiers[$tier] = array_values($this->cacheTiers[$tier]);
            }
        }
    }

    private function moveToHot($key)
    {
        $this->removeFromCacheTiers($key);
        array_unshift($this->cacheTiers['hot'], $key);

        if (isset($this->cache[$key])) {
            $this->cache[$key]['hits'] = ($this->cache[$key]['hits'] ?? 0) + 1;
        }
    }

    private function enforceCacheSize()
    {
        $totalCached = count($this->cache);
        if ($totalCached > $this->cacheMaxSize) {
            $toEvict = $totalCached - $this->cacheMaxSize;
            for ($i = 0; $i < $toEvict; $i++) {
                if (!empty($this->cacheTiers['cold'])) {
                    $evictKey = array_shift($this->cacheTiers['cold']);
                    unset($this->cache[$evictKey]);
                } elseif (!empty($this->cacheTiers['warm'])) {
                    $evictKey = array_shift($this->cacheTiers['warm']);
                    unset($this->cache[$evictKey]);
                }
            }
        }
    }
}
