<?php

namespace daytwo\blazingcache\services;

use Craft;
use craft\base\Component;
use craft\web\View;
use daytwo\blazingcache\services\CacheService;

class GeneratorService extends Component
{
    public function generateUri(string $uri, int $siteId = 1): array
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $baseUrl = rtrim($site->getBaseUrl(), '/');

        // Try internal rendering first
        try {
            // Render the _compiled_ templates for the route by using the view rendering
            $view = Craft::$app->getView();
            // Attempt to render the template path matching the URI (strip leading slash)
            $templatePath = ltrim($uri, '/');
            if ($templatePath === '') {
                $templatePath = 'index';
            }
            $html = $view->renderTemplate($templatePath);
        } catch (\Throwable $e) {
            $html = null;
        }

        // Fallback to HTTP fetch if internal render failed
        if ($html === null) {
            $url = $baseUrl . '/' . ltrim($uri, '/');

            // If running in Docker and baseUrl points to localhost, use host.docker.internal
            $parsed = parse_url($url);
            if ($parsed && isset($parsed['host']) && in_array($parsed['host'], ['localhost', '127.0.0.1'], true)) {
                if (getenv('DOCKER_HOST') !== false || file_exists('/.dockerenv')) {
                    $url = str_replace($parsed['host'], 'host.docker.internal', $url);
                }
            }

            $html = @file_get_contents($url);
            if ($html === false) {
                return ['success' => false, 'message' => "Failed to fetch $url"];
            }
        }

        // Save via cache service directly (don't require plugin instance)
        $hostName = parse_url($baseUrl, PHP_URL_HOST) ?: 'localhost';
        // Use the plugin-registered cache service when available
        try {
            $plugin = \daytwo\blazingcache\BlazingCache::getInstance();
            if ($plugin && method_exists($plugin, 'cacheService')) {
                $cache = $plugin->cacheService();
            } else {
                $cache = new CacheService([]);
            }
        } catch (\Throwable $e) {
            $cache = new CacheService([]);
        }

        $cache->setPage($hostName, $uri, $html);

        return ['success' => true, 'path' => $cache->pagePath($hostName, $uri)];
    }
}
