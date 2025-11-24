<?php

namespace daytwo\blazingcache\jobs;

use craft\queue\BaseJob;
use daytwo\blazingcache\purgers\DigitalOceanPurger;
use Craft;

class PurgeJob extends BaseJob
{
    /** @var string[] URLs to purge */
    public array $urls = [];

    /** @var bool dry-run flag */
    public bool $dry = false;

    public function execute($queue): void
    {
        $settings = \daytwo\blazingcache\BlazingCache::getInstance()->getSettings();
        if (empty($settings->doApiToken) || empty($settings->doCdnEndpoint)) {
            Craft::warning('PurgeJob: DO credentials not configured', __METHOD__);
            return;
        }

        $purger = new DigitalOceanPurger($settings->doApiToken, $settings->doCdnEndpoint);
        $purger->purge($this->urls, $this->dry);
    }

    public function defaultDescription(): ?string
    {
        return 'Purge CDN cache for ' . count($this->urls) . ' URL(s)';
    }
}
