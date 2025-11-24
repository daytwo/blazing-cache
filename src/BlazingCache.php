<?php

namespace daytwo\blazingcache;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\db\EntryQuery;
use craft\events\ElementEvent;
use craft\events\ModelEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\Request;
use craft\web\Response;
use craft\web\UrlManager;
use craft\web\View;
use daytwo\blazingcache\models\Settings;
use daytwo\blazingcache\purgers\DigitalOceanPurger;
use daytwo\blazingcache\services\CacheService;
use daytwo\blazingcache\services\GeneratorService;
use yii\base\Application;
use yii\base\Event;

class BlazingCache extends Plugin
{
    public $controllerNamespace = 'daytwo\\blazingcache\\controllers';

    public static BlazingCache $plugin;

    public bool $hasCpSettings = true;

    private array $configOverrides = [];

    private array $ruleSnapshots = [];

    public function init()
    {
        parent::init();
        self::$plugin = $this;

        $request = Craft::$app->getRequest();
        $this->controllerNamespace = $request->getIsConsoleRequest()
            ? 'daytwo\\blazingcache\\console'
            : 'daytwo\\blazingcache\\controllers';

        $this->setComponents([
            'cacheService' => CacheService::class,
            'generatorService' => GeneratorService::class,
        ]);

        try {
            $config = Craft::$app->getConfig()->getConfigFromFile('blazing-cache');
            $this->configOverrides = is_array($config) ? $config : [];
        } catch (\Throwable $e) {
            $this->configOverrides = [];
        }

        Craft::$app->on(Application::EVENT_BEFORE_REQUEST, function (): void {
            try {
                if (!$this->getSettings()->getEnabledBool()) {
                    return;
                }

                $request = Craft::$app->getRequest();
                if (!$request->getIsSiteRequest() || !$request->getIsGet() || $request->getIsPreview()) {
                    return;
                }

                $host = $request->getHostName();
                $uri = $this->buildCacheKey($request);
                $cached = $this->cacheService()->getPage($host, $uri);
                if ($cached === null) {
                    return;
                }

                $response = Craft::$app->getResponse();
                $response->format = Response::FORMAT_RAW;
                $response->content = $cached;
                $response->getHeaders()->set('X-Blazing-Cache', 'HIT');
                Craft::$app->end();
            } catch (\Throwable) {
                // Ignore cache issues so the request continues normally.
            }
        });

        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function (Event $event): void {
            try {
                if (!$this->getSettings()->getEnabledBool()) {
                    return;
                }

                $request = Craft::$app->getRequest();
                if (!$request->getIsSiteRequest() || !$request->getIsGet() || $request->getIsPreview()) {
                    return;
                }

                $response = $event->sender;
                if (!$response instanceof Response) {
                    return;
                }

                if ($response->getHeaders()->get('X-Blazing-Cache') === 'HIT') {
                    return;
                }

                if ($response->getStatusCode() !== 200 || $response->isRedirection) {
                    return;
                }

                $contentTypeHeader = $response->getHeaders()->get('Content-Type');
                if ($contentTypeHeader && stripos($contentTypeHeader, 'text/html') === false) {
                    return;
                }

                $content = $response->content ?? $response->data;
                if (!is_string($content) || $content === '') {
                    $buffer = ob_get_level() > 0 ? ob_get_contents() : false;
                    if ($buffer === false || $buffer === '') {
                        return;
                    }
                    $content = $buffer;
                }

                $host = $request->getHostName();
                $uri = $this->buildCacheKey($request);
                if ($this->cacheService()->setPage($host, $uri, $content)) {
                    $response->getHeaders()->set('X-Blazing-Cache', 'MISS');
                }
            } catch (\Throwable) {
                // Never block the response; cache write failures should be silent.
            }
        });

        Event::on(Entry::class, Entry::EVENT_BEFORE_SAVE, function (ModelEvent $event): void {
            $entry = $event->sender instanceof Entry ? $event->sender : null;
            $this->captureRuleState($entry);
        });

        Event::on(Entry::class, Entry::EVENT_AFTER_SAVE, function (ModelEvent $event): void {
            $entry = $event->sender instanceof Entry ? $event->sender : null;
            if (!$entry) {
                return;
            }

            if (!$this->shouldInvalidateElement($entry)) {
                $this->clearRuleSnapshot($entry);
                return;
            }

            $this->handleElementInvalidation($entry, 'entry');
            $this->applyConfiguredEntryTriggers($entry);
            $this->processEntryRulesAfterSave($entry, (bool) $event->isNew);
        });

        Event::on(Entry::class, Entry::EVENT_BEFORE_DELETE, function (Event $event): void {
            $entry = null;
            if ($event instanceof ElementEvent) {
                $entry = $event->element instanceof Entry ? $event->element : null;
            } elseif ($event->sender instanceof Entry) {
                $entry = $event->sender;
            }
            $this->captureRuleState($entry);
        });

        Event::on(Entry::class, Entry::EVENT_AFTER_DELETE, function (Event $event): void {
            $entry = null;
            if ($event instanceof ElementEvent) {
                $entry = $event->element instanceof Entry ? $event->element : null;
            } elseif ($event->sender instanceof Entry) {
                $entry = $event->sender;
            }

            if (!$entry) {
                return;
            }

            if (!$this->shouldInvalidateElement($entry)) {
                $this->clearRuleSnapshot($entry);
                return;
            }

            $this->handleElementInvalidation($entry, 'entry');
            $this->applyConfiguredEntryTriggers($entry);
            $this->processEntryRulesAfterDelete($entry);
        });

        Event::on(Asset::class, Asset::EVENT_AFTER_SAVE, function (ModelEvent $event): void {
            $asset = $event->sender instanceof Asset ? $event->sender : null;
            $this->handleElementInvalidation($asset, 'asset');
        });

        Event::on(Asset::class, Asset::EVENT_AFTER_DELETE, function (Event $event): void {
            $asset = null;
            if ($event instanceof ElementEvent) {
                $asset = $event->element instanceof Asset ? $event->element : null;
            } elseif ($event->sender instanceof Asset) {
                $asset = $event->sender;
            }
            $this->handleElementInvalidation($asset, 'asset');
        });

        Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, function (RegisterTemplateRootsEvent $event): void {
            $event->roots['blazing-cache'] = __DIR__ . '/templates';
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event): void {
            $event->rules['blazing-cache/settings'] = 'blazing-cache/settings/index';
        });

        $extensionClass = 'daytwo\\blazingcache\\twig\\BlazingCacheTwigExtension';
        if (class_exists($extensionClass)) {
            try {
                Craft::$app->getView()->registerTwigExtension(new $extensionClass());
            } catch (\Throwable) {
                // Ignore failures registering optional Twig extension.
            }
        }
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('blazing-cache/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    public function invalidateElement(string $elementType, int|string $elementId): void
    {
        try {
            $dependencies = $this->cacheService()->getDependenciesForElement($elementType, $elementId);
            if (!$dependencies) {
                $dependencies = $this->fallbackDependencies($elementType, $elementId);
            }

            if (!$dependencies) {
                return;
            }

            $urlsToPurge = [];
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $baseUrl = rtrim($site->getBaseUrl(), '/');
                $host = parse_url($site->getBaseUrl(), PHP_URL_HOST) ?: 'localhost';
                foreach ($dependencies as $uri) {
                    $uri = $this->normalizeDependencyUri((string) $uri);
                    $this->cacheService()->deletePage($host, $uri);
                    $urlsToPurge[] = $this->buildAbsoluteUrl($baseUrl, $uri);
                }
            }

            $settings = $this->getSettings();
            if ($settings->doApiToken && $settings->doCdnEndpoint) {
                $urls = array_values(array_unique($urlsToPurge));
                try {
                    $jobClass = 'daytwo\\blazingcache\\jobs\\PurgeJob';
                    if (class_exists($jobClass) && Craft::$app->getQueue()) {
                        $job = new $jobClass([
                            'urls' => $urls,
                            'dry' => false,
                        ]);
                        Craft::$app->getQueue()->push($job);
                    } else {
                        $purger = new DigitalOceanPurger($settings->doApiToken, $settings->doCdnEndpoint);
                        $purger->purge($urls, false);
                    }
                } catch (\Throwable) {
                    // CDN purge failures should not interrupt editor flow.
                }
            }
        } catch (\Throwable) {
            // Avoid throwing from invalidations so element saves complete.
        }
    }

    private function applyConfiguredEntryTriggers(Entry $entry): void
    {
        $entryTriggers = $this->getConfigArray('entryTriggers');
        if (!$entryTriggers) {
            return;
        }

        $section = $entry->getSection();
        if (!$section || !$section->handle) {
            return;
        }

        $triggers = $entryTriggers[$section->handle] ?? [];
        if (!is_array($triggers)) {
            return;
        }

        foreach ($triggers as $trigger) {
            $type = $trigger['type'] ?? null;
            $id = $trigger['id'] ?? null;
            if (!$type || $id === null || $id === '') {
                continue;
            }

            try {
                $this->invalidateElement((string) $type, $id);
            } catch (\Throwable) {
                // Ignore misconfigured triggers so editor flow continues.
            }
        }
    }

    private function captureRuleState(?Entry $entry): void
    {
        if (!$entry instanceof Entry) {
            return;
        }

        if (!$this->shouldInvalidateElement($entry)) {
            return;
        }

        $rules = $this->getConfigArray('entryInvalidationRules');
        if (!$rules) {
            return;
        }

        $objectHash = spl_object_hash($entry);

        foreach ($rules as $ruleKey => $ruleConfig) {
            if (!$this->ruleConfigAppliesToEntry($entry, $ruleConfig)) {
                continue;
            }

            try {
                $matches = $this->doesEntryMatchRule($entry, $ruleConfig);
            } catch (\Throwable) {
                $matches = false;
            }

            $this->ruleSnapshots[$objectHash][$ruleKey] = $matches;
        }
    }

    private function processEntryRulesAfterSave(Entry $entry, bool $isNew): void
    {
        $rules = $this->getConfigArray('entryInvalidationRules');
        if (!$rules) {
            $this->clearRuleSnapshot($entry);
            return;
        }

        $objectHash = spl_object_hash($entry);

        foreach ($rules as $ruleKey => $ruleConfig) {
            if (!$this->ruleConfigAppliesToEntry($entry, $ruleConfig)) {
                continue;
            }

            $previous = $this->ruleSnapshots[$objectHash][$ruleKey] ?? false;

            try {
                $current = $this->doesEntryMatchRule($entry, $ruleConfig);
            } catch (\Throwable) {
                $current = false;
            }

            if ($current !== $previous) {
                $this->invalidateElement('entry', $ruleKey);
            } elseif ($isNew && $current) {
                $this->invalidateElement('entry', $ruleKey);
            }
        }

        $this->clearRuleSnapshot($entry);
    }

    private function processEntryRulesAfterDelete(Entry $entry): void
    {
        $rules = $this->getConfigArray('entryInvalidationRules');
        if (!$rules) {
            $this->clearRuleSnapshot($entry);
            return;
        }

        $objectHash = spl_object_hash($entry);

        foreach ($rules as $ruleKey => $ruleConfig) {
            if (!$this->ruleConfigAppliesToEntry($entry, $ruleConfig)) {
                continue;
            }

            $previous = $this->ruleSnapshots[$objectHash][$ruleKey] ?? false;

            if ($previous) {
                $this->invalidateElement('entry', $ruleKey);
            }
        }

        $this->clearRuleSnapshot($entry);
    }

    private function doesEntryMatchRule(Entry $entry, array $ruleConfig): bool
    {
        $targetId = $this->resolveElementId($entry);
        if ($targetId === null) {
            return false;
        }

        $query = $this->buildRuleQuery($entry, $ruleConfig);
        if (!$query) {
            return false;
        }

        $entries = $query->all();
        if (!$entries) {
            return false;
        }

        foreach ($entries as $candidate) {
            if (!$candidate instanceof Entry) {
                continue;
            }

            $candidateId = $this->resolveElementId($candidate);
            if ($candidateId !== null && $candidateId === $targetId) {
                return true;
            }
        }

        return false;
    }

    private function buildRuleQuery(Entry $entry, array $ruleConfig): ?EntryQuery
    {
        $sectionHandles = $ruleConfig['section'] ?? null;
        $entrySection = $entry->getSection();

        if ($sectionHandles === null) {
            $sectionHandles = $entrySection ? $entrySection->handle : null;
        }

        if ($sectionHandles === null) {
            return null;
        }

        $handles = is_array($sectionHandles) ? $sectionHandles : [$sectionHandles];

        if ($entrySection && !in_array($entrySection->handle, $handles, true)) {
            return null;
        }

        $query = Entry::find()->section($handles);

        if (array_key_exists('siteId', $ruleConfig)) {
            $siteId = $ruleConfig['siteId'];
            if ($siteId !== '*' && $siteId !== ['*']) {
                $query->siteId($siteId);
            }
        } elseif (array_key_exists('site', $ruleConfig)) {
            $query->site($ruleConfig['site']);
        } elseif ($entry->siteId) {
            $query->siteId($entry->siteId);
        }

        $typeConstraint = $ruleConfig['type'] ?? $ruleConfig['entryType'] ?? null;
        if ($typeConstraint !== null) {
            $query->type($typeConstraint);
        }

        if (isset($ruleConfig['status'])) {
            $query->status($ruleConfig['status']);
        }

        if (isset($ruleConfig['orderBy'])) {
            $query->orderBy($ruleConfig['orderBy']);
        }

        if (isset($ruleConfig['limit'])) {
            $limit = (int) $ruleConfig['limit'];
            if ($limit > 0) {
                $query->limit($limit);
            }
        }

        if (isset($ruleConfig['where'])) {
            $query->andWhere($ruleConfig['where']);
        }

        if (isset($ruleConfig['with'])) {
            $query->with($ruleConfig['with']);
        }

        return $query;
    }

    private function ruleConfigAppliesToEntry(Entry $entry, array $ruleConfig): bool
    {
        $sectionHandles = $ruleConfig['section'] ?? null;
        $entrySection = $entry->getSection();

        if ($sectionHandles !== null) {
            $handles = is_array($sectionHandles) ? $sectionHandles : [$sectionHandles];
            if (!$entrySection || !in_array($entrySection->handle, $handles, true)) {
                return false;
            }
        } elseif (!$entrySection) {
            return false;
        }

        $typeConstraint = $ruleConfig['type'] ?? $ruleConfig['entryType'] ?? null;
        if ($typeConstraint !== null) {
            $types = is_array($typeConstraint) ? $typeConstraint : [$typeConstraint];
            $entryType = $entry->type->handle ?? null;
            if ($entryType === null || !in_array($entryType, $types, true)) {
                return false;
            }
        }

        if (isset($ruleConfig['siteId'])) {
            $siteIds = is_array($ruleConfig['siteId']) ? $ruleConfig['siteId'] : [$ruleConfig['siteId']];
            if ($siteIds !== ['*'] && !in_array($entry->siteId, $siteIds, true)) {
                return false;
            }
        }

        return true;
    }

    private function clearRuleSnapshot(Entry $entry): void
    {
        unset($this->ruleSnapshots[spl_object_hash($entry)]);
    }

    private function getConfigArray(string $key): array
    {
        $value = $this->configOverrides[$key] ?? [];
        return is_array($value) ? $value : [];
    }

    public function cacheService(): CacheService
    {
        return $this->get('cacheService');
    }

    private function buildCacheKey(Request $request): string
    {
        $path = trim($request->getPathInfo(), '/');
        $path = $path === '' ? 'index' : $path;

        $queryParams = $request->getQueryParams();
        $pathParam = Craft::$app->getConfig()->getGeneral()->pathParam ?? 'p';
        unset($queryParams[$pathParam]);

        if ($queryParams) {
            $normalized = $this->normalizeQueryParams($queryParams);
            $hash = md5(http_build_query($normalized));
            $path .= '/__qs/' . $hash;
        }

        return $path;
    }

    private function normalizeQueryParams(array $params): array
    {
        ksort($params);
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = $this->normalizeQueryParams($value);
            }
        }

        return $params;
    }

    private function fallbackDependencies(string $elementType, int|string $elementId): array
    {
        if ($elementType !== 'entry') {
            return [];
        }

        $uris = [];
        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            $entry = Craft::$app->getElements()->getElementById((int) $elementId, Entry::class, $siteId);
            if (!$entry) {
                continue;
            }

            $url = $entry->getUrl();
            if (!$url) {
                continue;
            }

            $uri = $this->uriFromUrl($url);
            if ($uri !== null) {
                $uris[] = $uri;
            }
        }

        return array_values(array_unique(array_filter($uris, static fn($value) => $value !== null)));
    }

    private function uriFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        if ($path === false && $query === false) {
            return null;
        }

        $path = $path ? trim($path, '/') : '';

        if ($query) {
            $hash = md5($query);
            $path = ($path !== '' ? $path . '/' : '') . '__qs/' . $hash;
        }

        return $path;
    }

    private function normalizeDependencyUri(string $uri): string
    {
        if ($uri === '__home__') {
            return '';
        }

        $uri = trim($uri, '/');

        if ($uri === 'index') {
            return '';
        }

        return $uri;
    }

    private function buildAbsoluteUrl(string $baseUrl, string $uri): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $uri = ltrim($uri, '/');
        return $uri === '' ? $baseUrl . '/' : $baseUrl . '/' . $uri;
    }

    private function handleElementInvalidation(?Element $element, string $type): void
    {
        if (!$this->shouldInvalidateElement($element)) {
            return;
        }

        $id = $this->resolveElementId($element);
        if ($id !== null) {
            $this->invalidateElement($type, $id);
        }
    }

    private function shouldInvalidateElement(?Element $element): bool
    {
        if (!$element || !$element instanceof Element) {
            return false;
        }

        if (($element->propagating ?? false) || ($element->resaving ?? false)) {
            return false;
        }

        if (method_exists($element, 'getIsDraft') && $element->getIsDraft()) {
            return false;
        }

        if (method_exists($element, 'getIsProvisionalDraft') && $element->getIsProvisionalDraft()) {
            return false;
        }

        if (method_exists($element, 'getIsRevision') && $element->getIsRevision()) {
            return false;
        }

        return true;
    }

    private function resolveElementId(?Element $element): ?int
    {
        if (!$element instanceof Element) {
            return null;
        }

        if (method_exists($element, 'getCanonicalId')) {
            $canonicalId = $element->getCanonicalId();
            if ($canonicalId) {
                return (int) $canonicalId;
            }
        }

        if (property_exists($element, 'canonicalId') && $element->canonicalId) {
            return (int) $element->canonicalId;
        }

        return $element->id ?? null;
    }
}
