<?php

return [
    'enabled' => false,
    'cachePath' => '@storage/cache/blazing-cache',
    'includedUriPatterns' => [
        [
            'siteId' => 1,
            'uriPattern' => '.*',
        ],
    ],
    // DigitalOcean settings
    'doApiToken' => null,
    'doCdnEndpoint' => null,
];
