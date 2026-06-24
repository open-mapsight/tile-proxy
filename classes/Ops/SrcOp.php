<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Ops;

use OpenMapsight\TileProxy\Result;
use RuntimeException;

class SrcOp implements OpHandler
{
    public function __invoke(callable $next, array $cfg, Result $res): Result
    {
        global $http_response_header;

        $res->mimeType = $cfg['mimeType'];
        $res->cacheBrowserTtl = $cfg['cacheBrowserTtl'];

        $invalidateCache = (static function () use ($res, $cfg) {
            if (
                is_int($res->getMetadata()->last4xx)
                && time() - ($cfg['cacheServer4xxTtl'] ?? 3600) < $res->getMetadata()->last4xx
            ) {
                // last request failed & we're in the retry cooldown window
                return false;
            }

            if (
                is_int($res->getMetadata()->last5xx)
                && time() - ($cfg['cacheServer5xxTtl'] ?? 300) < $res->getMetadata()->last5xx
            ) {
                // last request failed & we're in the retry cooldown window
                $res->cacheBrowserTtl = $cfg['cacheBrowserTtlFail'];
                return false;
            }

            $mTime = $res->getCacheMTime();

            if ($mTime === null) {
                return true;
            }

            if ($cfg['cacheServerTtl'] < time() - $mTime) {
                return true;
            }

            return false;
        })();

        if ($invalidateCache) {
            $url = $this->getSrcUrl($cfg, $res);
            // TODO: send `Accept` header with expected mimetype
            // TODO: check that the mimetype matches the expected mimetype

            $httpResponseHeaderBefore = $http_response_header ?? null;

            $content = @file_get_contents(
                $url,
                false,
                stream_context_create($cfg['streamContext'] ?? [])
            );

            $httpResCode = null;

            if ($http_response_header !== $httpResponseHeaderBefore) {
                // $http_response_header contains our response & `file_get_contents` was an
                // http request

                // https://www.php.net/manual/en/reserved.variables.httpresponseheader.php
                preg_match('/^HTTP\/[\d.]+ (\d{3}) /', $http_response_header[0], $matches);

                $httpResCode = $matches[1] ?? null;
            }

            if ($content === false) {
                if (400 <= $httpResCode && $httpResCode <= 499) {
                    $res->getMetadata()->last4xx = time();
                } else if (500 <= $httpResCode && $httpResCode <= 599) {
                    $res->getMetadata()->last5xx = time();
                    $res->cacheBrowserTtl = $cfg['cacheBrowserTtlFail'];
                }
            } else {
                $res->setData($content);
            }
        }

        return $next($res);
    }

    private function getSrcUrl(array $cfg, Result $res): string
    {
        if (empty($cfg['urls'])) {
            throw new RuntimeException('No urls configured');
        }

        $url = $cfg['urls'][array_rand($cfg['urls'])];

        $reqArgs = $res->getReqArgs();
        if ($reqArgs['prefix'] !== null) {
            $url = str_replace('{prefix}', $reqArgs['prefix'], $url);
        }

        $url = str_replace('{z}', (string)$reqArgs['z'], $url);
        $url = str_replace('{x}', (string)$reqArgs['x'], $url);
        return str_replace('{y}', (string)$reqArgs['y'], $url);
    }
}
