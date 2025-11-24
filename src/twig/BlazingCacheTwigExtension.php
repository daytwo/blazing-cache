<?php

namespace daytwo\blazingcache\twig;

use daytwo\blazingcache\BlazingCache;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class BlazingCacheTwigExtension extends AbstractExtension
{
    public function getFunctions()
    {
        return [
            new TwigFunction('blazing_cache_depends_on', [$this, 'dependsOn']),
        ];
    }

    public function dependsOn(string $elementType, int|string $elementId, string $uri): bool
    {
        try {
            $plugin = BlazingCache::getInstance();
            $plugin->cacheService()->addDependency($elementType, $elementId, $uri);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
