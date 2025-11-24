<?php

namespace daytwo\blazingcache\controllers;

use craft\helpers\UrlHelper;
use craft\web\Controller;
use Craft;
use daytwo\blazingcache\BlazingCache;

class SettingsController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): ?\yii\web\Response
    {
        $this->requireAdmin();
        return $this->redirect(UrlHelper::cpUrl('settings/plugins/blazing-cache'));
    }

    public function actionSave(): ?\yii\web\Response
    {
        $this->requirePostRequest();

        $plugin = BlazingCache::getInstance();
        $settings = $plugin->getSettings();

        // Basic fields
        $enabledRaw = Craft::$app->getRequest()->getBodyParam('enabled');
        if ($enabledRaw !== null) {
            if ($enabledRaw === true || $enabledRaw === 1 || $enabledRaw === '1') {
                $settings->enabled = true;
            } elseif ($enabledRaw === false || $enabledRaw === 0 || $enabledRaw === '0') {
                $settings->enabled = false;
            } else {
                $settings->enabled = $enabledRaw;
            }
        }
        $settings->cachePath = trim((string)Craft::$app->getRequest()->getBodyParam('cachePath')) ?: $settings->cachePath;

        // Allow users to save either a literal value or an env reference ($VAR_NAME).
        // We intentionally do not block saving when environment variables exist so
        // the user can explicitly reference them using the $NAME syntax.
        $doApiTokenRaw = Craft::$app->getRequest()->getBodyParam('doApiToken');
        $doCdnEndpointRaw = Craft::$app->getRequest()->getBodyParam('doCdnEndpoint');

        $settings->doApiToken = $doApiTokenRaw !== null ? trim((string)$doApiTokenRaw) ?: null : $settings->doApiToken;
        $settings->doCdnEndpoint = $doCdnEndpointRaw !== null ? trim((string)$doCdnEndpointRaw) ?: null : $settings->doCdnEndpoint;

        if ($settings->validate()) {
            // Save as an array of attributes (preserve any $VAR_NAME strings)
            $success = Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->getAttributes());
            if ($success) {
                Craft::$app->getSession()->setFlash('success', 'Settings saved.');
            }
        }

        return $this->redirectToPostedUrl();
    }

    public function actionTestPurge(): ?\yii\web\Response
    {
        $this->requirePostRequest();
        $dry = (bool)Craft::$app->getRequest()->getBodyParam('dry', true);
        $urlsRaw = Craft::$app->getRequest()->getBodyParam('urls', '');

        // Accept a textarea with one URL per line, or an array
        if (is_array($urlsRaw)) {
            $urls = $urlsRaw;
        } else {
            $lines = preg_split('/\r?\n/', (string)$urlsRaw);
            $urls = array_values(array_filter(array_map('trim', $lines)));
        }

        $plugin = BlazingCache::getInstance();
        $settings = $plugin->getSettings();

        // Resolve any $ENV_VAR references stored in settings
        $resolvedToken = $settings->resolveEnv($settings->doApiToken);
        $resolvedEndpoint = $settings->resolveEnv($settings->doCdnEndpoint);

        if (empty($resolvedToken) || empty($resolvedEndpoint)) {
            Craft::$app->getSession()->setFlash('error', 'DigitalOcean credentials not configured. Provide literals or use $ENV_VAR references.');
            return $this->redirectToPostedUrl();
        }

        $purgerClass = 'daytwo\\blazingcache\\purgers\\DigitalOceanPurger';
        $purger = new $purgerClass($resolvedToken, $resolvedEndpoint);

        if ($dry) {
            // Let the purger log the dry-run details to Craft logs (web.log)
            try {
                $purger->purge($urls, true);
                Craft::$app->getSession()->setFlash('info', 'Dry-run purge executed and logged to system log. Check storage/logs/web.log');
            } catch (\Throwable $e) {
                Craft::$app->getSession()->setFlash('error', 'Dry-run purge failed: ' . $e->getMessage());
            }

            return $this->redirectToPostedUrl();
        }
        $ok = false;
        try {
            $ok = $purger->purge(array_values((array)$urls), false);
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setFlash('error', 'Purge error: ' . $e->getMessage());
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setFlash($ok ? 'success' : 'error', $ok ? 'Purge request sent' : 'Purge failed');
        return $this->redirectToPostedUrl();
    }
}
