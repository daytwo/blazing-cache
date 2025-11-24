<?php

namespace daytwo\blazingcache\services;

use Craft;
use craft\base\ApplicationTrait;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\FileHelper;

class CacheService extends Component
{
    private string $cachePath;

    public function __construct($config = [])
    {
        parent::__construct($config);
        // Prefer plugin-configured cachePath when available (resolve aliases)
        $cachePath = null;
        try {
            if (class_exists('daytwo\\blazingcache\\BlazingCache')) {
                $plugin = \daytwo\blazingcache\BlazingCache::getInstance();
                if ($plugin) {
                    $settings = $plugin->getSettings();
                    $cachePath = $settings->cachePath ?? null;
                }
            }
        } catch (\Throwable $e) {
            // fall back
        }

        if (empty($cachePath)) {
            $cachePath = '@storage/cache/blazing-cache';
        }

        $this->cachePath = Craft::getAlias($cachePath);
        if (!is_dir($this->cachePath)) {
            @mkdir($this->cachePath, 0775, true);
        }
    }

    public function setPage(string $siteHost, string $uri, string $html): bool
    {
        $path = $this->pagePath($siteHost, $uri);
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        return (bool) file_put_contents($path, $html);
    }

    // expose pagePath for generator feedback
    public function pagePath(string $siteHost, string $uri): string
    {
        return $this->buildPagePath($siteHost, $uri);
    }

    public function getPage(string $siteHost, string $uri): ?string
    {
        $path = $this->pagePath($siteHost, $uri);
        if (is_file($path)) {
            return file_get_contents($path);
        }
        return null;
    }

    public function deletePage(string $siteHost, string $uri): bool
    {
        $path = $this->pagePath($siteHost, $uri);
        if (is_file($path)) {
            return @unlink($path);
        }
        return false;
    }

    public function clearHost(string $siteHost): bool
    {
        $target = $this->cachePath . '/' . $this->sanitizeHost($siteHost);
        if (!is_dir($target)) {
            return true;
        }

        try {
            FileHelper::removeDirectory($target);
            return true;
        } catch (\Throwable $e) {
            Craft::error('Failed clearing Blazing Cache host directory: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    public function clearAllHosts(): bool
    {
        if (!is_dir($this->cachePath)) {
            return true;
        }

        $entries = @scandir($this->cachePath) ?: [];
        $ok = true;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->cachePath . '/' . $entry;

            if (is_dir($path)) {
                try {
                    FileHelper::removeDirectory($path);
                } catch (\Throwable $e) {
                    Craft::error('Failed clearing Blazing Cache directory "' . $path . '": ' . $e->getMessage(), __METHOD__);
                    $ok = false;
                }
            } elseif (is_file($path)) {
                $ok = @unlink($path) && $ok;
            }
        }

        $depsRoot = Craft::getAlias('@storage') . '/cache/blazing-cache/deps';
        if (is_dir($depsRoot)) {
            try {
                FileHelper::removeDirectory($depsRoot);
            } catch (\Throwable $e) {
                Craft::error('Failed clearing Blazing Cache dependency map: ' . $e->getMessage(), __METHOD__);
                $ok = false;
            }
        }

        return $ok;
    }

    private function buildPagePath(string $siteHost, string $uri): string
    {
        $safeUri = trim($uri, '/');
        $query = '';
        if (str_contains($safeUri, '?')) {
            [$safeUri, $query] = explode('?', $safeUri, 2);
        }

        if ($safeUri === '') {
            $segments = ['index'];
        } else {
            $segments = array_filter(explode('/', $safeUri), static fn($segment) => $segment !== '');
            if (empty($segments)) {
                $segments = ['index'];
            }
        }

        if ($query !== '') {
            $segments[] = '__qs';
            $segments[] = md5($query);
        }

        $segments = array_map(function (string $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                return '_';
            }
            return preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $segment);
        }, $segments);

        $relativePath = implode('/', $segments);

        // store under host/uri/index.html
        return $this->cachePath . '/' . $this->sanitizeHost($siteHost) . '/' . $relativePath . '/index.html';
    }

    private function sanitizeHost(string $host): string
    {
        return preg_replace('/[^a-z0-9\.\-]/', '_', strtolower($host));
    }

    // Simple dependency tracking API: adds fragment identifier to dep set for element
    public function addDependency(string $elementType, string|int $elementId, string $fragmentKey): void
    {
        // Try Redis if available
        try {
            if (class_exists('\yii\redis\Connection') && isset(Craft::$app->redis)) {
                $redis = Craft::$app->redis; // connection component
                $key = "blazing:deps:{$elementType}:{$elementId}";
                $redis->executeCommand('SADD', [$key, $fragmentKey]);
                return;
            }
        } catch (\Throwable $e) {
            // fallthrough to file fallback
        }

        // File fallback
        $depsPath = Craft::getAlias('@storage') . "/cache/blazing-cache/deps/{$elementType}/{$elementId}.json";
        if (!is_dir(dirname($depsPath))) {
            @mkdir(dirname($depsPath), 0775, true);
        }
        $arr = [];
        if (is_file($depsPath)) {
            $arr = json_decode(file_get_contents($depsPath), true) ?: [];
        }
        if (!in_array($fragmentKey, $arr, true)) {
            $arr[] = $fragmentKey;
            file_put_contents($depsPath, json_encode($arr));
        }
    }

    public function getDependenciesForElement(string $elementType, string|int $elementId): array
    {
        try {
            if (class_exists('\yii\redis\Connection') && isset(Craft::$app->redis)) {
                $redis = Craft::$app->redis;
                $key = "blazing:deps:{$elementType}:{$elementId}";
                return $redis->executeCommand('SMEMBERS', [$key]) ?: [];
            }
        } catch (\Throwable $e) {
        }
        $depsPath = Craft::getAlias('@storage') . "/cache/blazing-cache/deps/{$elementType}/{$elementId}.json";
        if (is_file($depsPath)) {
            return json_decode(file_get_contents($depsPath), true) ?: [];
        }
        return [];
    }
}
