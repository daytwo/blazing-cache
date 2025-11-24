<?php

namespace daytwo\blazingcache\jobs;

use craft\queue\BaseJob;
use daytwo\blazingcache\BlazingCache;

class GenerateJob extends BaseJob
{
    public string $uri = '/';
    public int $siteId = 1;

    public function execute($queue): void
    {
        $generatorClass = 'daytwo\\blazingcache\\services\\GeneratorService';
        if (!class_exists($generatorClass)) {
            return;
        }
        $generator = new $generatorClass([]);
        $generator->generateUri($this->uri, $this->siteId);
    }

    public function defaultDescription(): ?string
    {
        return "Generate {$this->uri} on site {$this->siteId}";
    }
}
