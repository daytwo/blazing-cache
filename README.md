# Blazing Cache

Blazing Cache provides static-page caching for Craft CMS while staying out of the editor’s way. It serves cached HTML responses on GET requests, writes fresh cache copies after each render, and automatically purges affected pages whenever entries or assets change.

## Features

- **Full-page caching** – caches site requests to disk (or optional CDN/Redis integrations) and serves them instantly on repeat visits.
- **Automatic invalidation** – purges cached pages whenever related entries or assets are saved or deleted.
- **Twig dependency helper** – declare dependencies from your templates using `blazing_cache_depends_on()` so pages clear themselves when referenced elements change.
- **Config-driven rules** – extend invalidation behaviour via `config/blazing-cache.php` (e.g. keep “latest news” modules fresh across many pages).
- **DigitalOcean CDN purge** – optionally queues purge calls to DigitalOcean’s CDN when credentials are supplied.

## Installation

1. Copy the plugin into `plugins/blazing-cache` and install/enable it via the Craft control panel.
2. (Optional) Provide DigitalOcean API credentials in the plugin settings for CDN purging.
3. (Optional) Create `config/blazing-cache.php` to describe custom invalidation triggers or rules (see below).

## Usage

### Register dependencies in Twig

Whenever a page depends on specific elements, register the dependency so Blazing Cache knows which URIs to purge:

```twig
{% set currentUri = craft.app.request.pathInfo ?: '__home__' %}
{% do blazing_cache_depends_on('entry', entry.id, currentUri) %}
```

For lists that aggregate other elements, register each related ID and an optional sentinel for the collection:

```twig
{% for article in latestArticles %}
	{% do blazing_cache_depends_on('entry', article.id, currentUri) %}
{% endfor %}

{# Sentinel for the list so “latest N” rules can target it #}
{% do blazing_cache_depends_on('entry', 'news.latest_3', currentUri) %}
```

### Configure invalidation behaviour

Add `config/blazing-cache.php` to describe extra invalidation logic:

```php
<?php

return [
	'entryTriggers' => [
		// Force-invalidate this sentinel whenever a news entry changes.
		'news' => [
			['type' => 'entry', 'id' => 'news.latest'],
		],
	],
	'entryInvalidationRules' => [
		// Only invalidate when the entry belongs to the current top three articles.
		'news.latest_3' => [
			'section' => 'news',
			'limit' => 3,
			'orderBy' => 'postDate DESC',
			'status' => ['live'],
		],
	],
];
```

- `entryTriggers` fire unconditionally on save/delete — use this for simple sentinels referenced in Twig.
- `entryInvalidationRules` run a Craft query after each change; the sentinel is only purged when the edited entry appears in the result set (ideal for “latest N” lists).

### DigitalOcean CDN purge

Supplying a DigitalOcean API token and endpoint in the plugin settings queues purge jobs for every invalidated URL (falling back to synchronous purge if the queue is unavailable).

### Console commands

Run Blazing Cache console actions with `php craft blazing-cache/cache/<command>`:

- `generate [uri] --siteId=1 [--enqueue=1]` – render a single URI immediately or enqueue a warm-up job when the queue is running. Defaults to `/` on the primary site.
- `clear [uri] --siteId=1 [--all=1]` – delete a cached URI, clear an entire host, or wipe every cached host by combining `--all=1` with `--siteId=*`.
- `purge --urls='/,/news/latest' [--dry=1] [--enqueue=1]` – purge one or more absolute URLs from the configured CDN. Requires DigitalOcean credentials in the plugin settings.

Use the short aliases `-s`, `-e`, `-a`, `-u`, and `-d` if you prefer concise flags (e.g. `php craft blazing-cache/cache/generate /about -s=2 -e=1`). When `--enqueue=1` is provided, the command pushes a job onto Craft’s queue and returns immediately; otherwise it runs inline.

## Environment configuration

Declare the cache-related environment variables in `.env` so you can toggle behaviour per environment and avoid checking secrets into git:

```dotenv
BLAZING_CACHE_ENABLED=1
BLAZING_CACHE_DO_CDN_ENDPOINT=""
BLAZING_CACHE_DO_API_TOKEN=""
```

- `BLAZING_CACHE_ENABLED` – switch the plugin on/off without redeploying. In the Craft control panel set **Enabled** to `$BLAZING_CACHE_ENABLED` so each environment inherits its own value.
- `BLAZING_CACHE_DO_CDN_ENDPOINT` – the full DigitalOcean CDN endpoint URL (for example `https://api.digitalocean.com/v2/cdn/endpoints/<uuid>/cache`). Leave blank to disable CDN purge.
- `BLAZING_CACHE_DO_API_TOKEN` – a personal access token that can purge the endpoint. Reference it in the settings as `$BLAZING_CACHE_DO_API_TOKEN`.

## Nginx integration

Expose the cached pages directly to Nginx so repeat visitors bypass PHP entirely. Add a symlink (see below), then drop the following inside your site’s `server {}` block:

```nginx
# Optional helpers to decide when PHP should handle the request instead
map $request_method $blazing_skip_method { default 1; GET 0; HEAD 0; }
map $query_string $blazing_skip_query { default 1; "" 0; }
map $http_cookie $blazing_skip_cookie { default 0; "~*(CraftSessionId|CraftAuth|CraftToken)" 1; }

# Resolve to the cached paths when no bypass rule fired
map "$blazing_skip_method$blazing_skip_query$blazing_skip_cookie" $blazing_cache_primary {
	default "";
	"000"  "/blazing-cache/$host$uri/index.html";
}

map "$blazing_skip_method$blazing_skip_query$blazing_skip_cookie" $blazing_cache_fallback {
	default "";
	"000"  "/blazing-cache/$host$uri/index/index.html";
}

location /blazing-cache/ {
	internal;
}

location / {
	try_files $blazing_cache_primary $blazing_cache_fallback @craft;
}

location @craft {
	try_files $uri $uri/ /index.php?$query_string;
}
```

Requests that are not simple `GET`/`HEAD` requests without Craft cookies fall straight through to `@craft`. When the variables resolve to empty strings, `try_files` ignores them and immediately hands control to the Craft fallback. Tweak the maps to match your own Login/preview rules.

## CDN deployment checklist

Pair the on-disk cache with a pull CDN so public traffic stays fast while editors work against the origin:

- **Domain split** – point `admin.example.com` (or similar) straight at Craft for the control panel, and map the public hostname (e.g. `example.com`) to your CDN endpoint. Redirect any `www` host to the bare domain before it hits Craft.
- **Origin config** – the CDN origin should remain your Craft server so it can fetch cache files from `web/blazing-cache`. Ensure TLS certificates cover the CDN hostname.
- **Bypass sensitive paths** – add CDN rules or headers to disable caching for `/admin`, `/actions/*`, `/cpresources/*`, and requests with `CraftSessionId`, `CraftAuth`, or `CraftToken` cookies.
- **Purge integration** – supply `BLAZING_CACHE_DO_API_TOKEN` and `BLAZING_CACHE_DO_CDN_ENDPOINT` so Blazing Cache can call the DigitalOcean CDN purge API whenever pages change. Keep queue workers running to process purge jobs.
- **Warm-up strategy** – optional but recommended: use the bundled generator command (`php craft blazing-cache/cache/generate`) to pre-fill popular pages before pointing real users at the CDN.

## Expose cached files to the web server

The plugin writes HTML copies under `storage/cache/blazing-cache/{host}/{path}/index.html`. Create a symlink so that directory is reachable from the web root:

```shell
cd /path/to/project
rm -rf web/blazing-cache
ln -s ../storage/cache/blazing-cache web/blazing-cache
```

Run this during provisioning/deploys so the link survives fresh checkouts. Ensure the web server user can read both the symlink and the underlying `storage` directory.

## Development notes

- Cached pages live under `storage/cache/blazing-cache/{host}/{uri}/index.html`.
- Dependency data is stored in Redis when available, or in `storage/cache/blazing-cache/deps` as JSON.
- Preview requests (`craft.app.request.isPreview`) bypass the cache entirely so editors always see live data.

## Contributing

Issues and pull requests are welcome. Common extensions include alternative CDN purgers, additional cache backends, and tooling around dependency management.
