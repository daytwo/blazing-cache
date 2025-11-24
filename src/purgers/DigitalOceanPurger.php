<?php

namespace daytwo\blazingcache\purgers;

use Craft;

final class DigitalOceanPurger
{
    public function __construct(
        private string $apiToken,
        private string $cdnEndpoint,
        private int $maxRetries = 3,
        private int $baseBackoffMs = 200
    ) {
    }

    public function purge(array $urls, bool $dryRun = false): bool
    {
        // Keep only a useful dry-run payload print for CLI visibility

        if (empty($urls)) {
            Craft::info('DigitalOceanPurger: nothing to purge', __METHOD__);
            return true;
        }

        if (empty($this->apiToken) || empty($this->cdnEndpoint)) {
            Craft::error('DigitalOceanPurger: missing API token or CDN endpoint', __METHOD__);
            return false;
        }

        $payload = json_encode(['files' => array_values($urls)]);

        if ($dryRun) {
            Craft::info('DigitalOceanPurger (dry-run): would purge ' . count($urls) . ' url(s): ' . implode(', ', array_slice($urls, 0, 10)), __METHOD__);
            Craft::info('DigitalOceanPurger (dry-run) payload: ' . $payload, __METHOD__);
            // Dry-run logged above via Craft::info; do not echo to stdout in production.
            return true;
        }

        $endpoint = rtrim($this->cdnEndpoint, '/') . '/cache';

        $attempt = 0;
        $lastException = null;

        while ($attempt <= $this->maxRetries) {
            $attempt++;
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $endpoint);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiToken,
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);

                $res = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $errNo = curl_errno($ch);
                $err = curl_error($ch);
                curl_close($ch);

                if ($errNo) {
                    throw new \RuntimeException('curl error #' . $errNo . ': ' . $err);
                }

                if ($code >= 200 && $code < 300) {
                    Craft::info("DigitalOceanPurger: purge successful (attempt {$attempt}) HTTP {$code}", __METHOD__);
                    return true;
                }

                Craft::warning("DigitalOceanPurger: non-2xx response (attempt {$attempt}) HTTP {$code}. Response: " . substr((string)$res, 0, 2000), __METHOD__);
                $lastException = new \RuntimeException('Non-2xx response: ' . $code);
            } catch (\Throwable $e) {
                $lastException = $e;
                Craft::warning('DigitalOceanPurger: attempt ' . $attempt . ' failed: ' . $e->getMessage(), __METHOD__);
            }

            if ($attempt <= $this->maxRetries) {
                $backoffMs = (int)($this->baseBackoffMs * (2 ** ($attempt - 1)));
                $backoffMs += rand(0, (int)($backoffMs * 0.25));
                usleep($backoffMs * 1000);
            }
        }

        if ($lastException) {
            Craft::error('DigitalOceanPurger: all attempts failed. Last error: ' . $lastException->getMessage(), __METHOD__);
        }

        return false;
    }
}
