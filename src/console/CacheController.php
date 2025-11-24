<?php

namespace daytwo\blazingcache\console;

use Craft;
use craft\console\Controller;
use daytwo\blazingcache\jobs\GenerateJob;
use yii\console\ExitCode;

class CacheController extends Controller
{
    /** @var int|string|null */
    public int|string|null $siteId = null;
    /** @var int|string|null */
    public int|string|null $enqueue = null;
    /** @var string|null */
    public ?string $urls = null;
    /** @var int|string|null */
    public int|string|null $dry = null;
    /** @var int|string|null */
    public int|string|null $all = null;

    /**
     * Define available options per action.
     */
    public function options($actionID): array
    {
        switch ($actionID) {
            case 'purge':
                return ['urls', 'dry', 'enqueue'];
            case 'generate':
                return ['siteId', 'enqueue'];
            case 'clear':
                return ['siteId', 'all'];
            default:
                return parent::options($actionID) ?: [];
        }
    }

    /**
     * Short aliases for options.
     */
    public function optionAliases(): array
    {
        return [
            'u' => 'urls',
            'd' => 'dry',
            'e' => 'enqueue',
            's' => 'siteId',
            'a' => 'all',
        ];
    }

    /**
     * Generate a single URI or enqueue a generation job.
     */
    public function actionGenerate(string $uri = '/', int|string|null $siteId = null, int|string|null $enqueue = null): int
    {
        $siteId = (int)($this->siteId ?? $siteId ?? 1);
        $enqueue = (bool)($this->enqueue ?? $enqueue ?? 0);

        if ($enqueue) {
            $job = new GenerateJob([
                'uri' => $uri,
                'siteId' => $siteId,
            ]);
            try {
                if (isset(Craft::$app->queue)) {
                    Craft::$app->queue->push($job);
                    $this->stdout("Enqueued generation job for {$uri}\n");
                    return ExitCode::OK;
                }
            } catch (\Throwable $e) {
                $this->stderr('Enqueue failed: ' . $e->getMessage() . "\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $result = \daytwo\blazingcache\BlazingCache::getInstance()->generatorService->generateUri($uri, $siteId);
        if ($result['success']) {
            $this->stdout('Generated: ' . ($result['path'] ?? 'unknown') . "\n");
            return ExitCode::OK;
        }

        $this->stderr('Generate failed: ' . ($result['message'] ?? 'unknown') . "\n");
        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Clear cached content.
     */
    public function actionClear(string $uri = '/', int|string|null $siteId = null, int|string|null $all = null): int
    {
        $siteId = $this->siteId ?? $siteId ?? 1;
        $all = (bool)($this->all ?? $all ?? 0);

        $cacheService = \daytwo\blazingcache\BlazingCache::getInstance()->cacheService();

        if ($all && ($siteId === '*' || $siteId === 'all')) {
            $ok = $cacheService->clearAllHosts();
            $this->stdout($ok ? "Cleared all cached hosts\n" : "Failed to clear all cached hosts\n");
            return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }

        $site = Craft::$app->getSites()->getSiteById((int)$siteId);
        if (!$site) {
            $this->stderr("Unknown site ID {$siteId}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $host = $site->getBaseUrl();
        $hostName = parse_url($host, PHP_URL_HOST) ?: 'localhost';

        if ($all || $uri === '*' || strtolower($uri) === 'all') {
            $ok = $cacheService->clearHost($hostName);
            $this->stdout($ok ? "Cleared cache for host {$hostName}\n" : "Failed clearing cache for host {$hostName}\n");
            return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }

        $ok = $cacheService->deletePage($hostName, $uri);
        $this->stdout($ok ? "Deleted {$uri}\n" : "Not found {$uri}\n");
        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Purge URLs from the CDN or enqueue a purge job.
     */
    public function actionPurge(string $urls = '', int|string|null $dry = null, int|string|null $enqueue = null): int
    {
        $urls = $this->urls ?? $urls ?? '';
        $dry = (int)($this->dry ?? $dry ?? 0);
        $enqueue = (int)($this->enqueue ?? $enqueue ?? 0);

        $urlsList = [];
        if ($urls !== '') {
            $urlsList = array_filter(array_map('trim', explode(',', $urls)));
        }

        if (empty($urlsList)) {
            $this->stderr("No urls specified. Use --urls='/foo,/bar' or pass first arg\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $settings = \daytwo\blazingcache\BlazingCache::getInstance()->getSettings();
        if (method_exists($settings, 'resolveEnv')) {
            $doApiToken = $settings->resolveEnv($settings->doApiToken);
            $doCdnEndpoint = $settings->resolveEnv($settings->doCdnEndpoint);
        } else {
            $doApiToken = $settings->doApiToken;
            $doCdnEndpoint = $settings->doCdnEndpoint;
        }

        if (empty($doApiToken) || empty($doCdnEndpoint)) {
            $this->stderr("DigitalOcean credentials not configured in plugin settings\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $urlsList = array_values(array_unique($urlsList));
        $purgeJobClass = 'daytwo\\blazingcache\\jobs\\PurgeJob';

        try {
            if ($enqueue && class_exists($purgeJobClass) && isset(Craft::$app->queue)) {
                $job = new $purgeJobClass([
                    'urls' => $urlsList,
                    'dry' => (bool)$dry,
                ]);
                Craft::$app->queue->push($job);
                $this->stdout('Enqueued purge job for ' . count($urlsList) . " URL(s)\n");
                return ExitCode::OK;
            }

            $purger = new \daytwo\blazingcache\purgers\DigitalOceanPurger($doApiToken, $doCdnEndpoint);
            $ok = $purger->purge($urlsList, (bool)$dry);
            $this->stdout($ok ? "Purge request completed\n" : "Purge request failed\n");
            return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        } catch (\Throwable $e) {
            $this->stderr('Purge failed: ' . $e->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}
