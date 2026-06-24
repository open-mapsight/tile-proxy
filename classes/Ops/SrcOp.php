<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Ops;

use OpenMapsight\TileProxy\Result;
use OpenMapsight\TileProxy\UpstreamFetcher;
use RuntimeException;

class SrcOp implements OpHandler
{
    public function __invoke(callable $next, array $cfg, Result $res): Result
    {
        $res->mimeType = $cfg['mimeType'];
        $res->cacheBrowserTtl = $cfg['cacheBrowserTtl'];

        $invalidateCache = (static function () use ($res, $cfg) {
            if (
                $res->getMetadata()->getLast4xx() !== null
                && time() - ($cfg['cacheServer4xxTtl'] ?? 3600) < $res->getMetadata()->getLast4xx()
            ) {
                // last request failed & we're in the retry cooldown window
                return false;
            }

            if (
                $res->getMetadata()->getLast5xx() !== null
                && time() - ($cfg['cacheServer5xxTtl'] ?? 300) < $res->getMetadata()->getLast5xx()
            ) {
                // last request failed & we're in the retry cooldown window
                $res->cacheBrowserTtl = $cfg['cacheBrowserTtlFail'] ?? 300;
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
            $mimeType = $cfg['mimeType'] ?? null;
            $fetchResult = UpstreamFetcher::fetch(
                $url,
                $cfg['upstreamHttp'] ?? [],
                $mimeType,
                $mimeType
            );

            if (!$fetchResult->isSuccess()) {
                $httpResCode = $fetchResult->statusCode;

                if ($httpResCode !== null && 400 <= $httpResCode && $httpResCode <= 499) {
                    $res->getMetadata()->setLast4xx(time());
                } else if ($httpResCode !== null && 500 <= $httpResCode && $httpResCode <= 599) {
                    $res->getMetadata()->setLast5xx(time());
                    $res->cacheBrowserTtl = $cfg['cacheBrowserTtlFail'] ?? 300;
                }
            } else {
                $res->setData($fetchResult->body);
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
