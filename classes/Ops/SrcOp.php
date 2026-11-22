<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Ops;

use OpenMapsight\TileProxy\Log;
use OpenMapsight\TileProxy\Result;
use OpenMapsight\TileProxy\TileNotFoundPolicy;
use OpenMapsight\TileProxy\UpstreamCircuitBreaker;
use OpenMapsight\TileProxy\UpstreamFetcher;
use OpenMapsight\TileProxy\UpstreamFetchResult;
use OpenMapsight\TileProxy\Utils;
use OpenMapsight\TileProxy\WmsUrl;
use RuntimeException;

class SrcOp implements OpHandler
{
    public function __invoke(callable $next, array $cfg, Result $res): Result
    {
        $res->mimeType = $cfg['mimeType'];
        $res->cacheBrowserTtl = $cfg['cacheBrowserTtl'];

        $notFound = TileNotFoundPolicy::fromConfig($cfg);
        $breakerEnabled = isset($cfg['upstreamCircuitBreaker']);
        $metadata = $res->getMetadata();
        if ($notFound !== null) {
            $res->cacheBrowserTtlNotFound = 0;
            $last404 = $metadata->getLast404();
            if ($last404 !== null && $notFound->remainingServerTtl($last404) > 0) {
                $this->applyNotFoundTtl($res, $notFound->browserTtl($last404));
                return $next($res);
            }
        }

        $invalidateCache = (static function () use ($res, $cfg, $breakerEnabled) {
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
                // last request failed temporarily & we're in the retry cooldown window
                if ($breakerEnabled) {
                    $res->cacheBrowserTtlNotFound = 0;
                }
                $res->cacheBrowserTtl = min($res->cacheBrowserTtl, $cfg['cacheBrowserTtlFail'] ?? 300);
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
            $httpConfig = $cfg['upstreamHttp'] ?? [];
            if ($breakerEnabled) {
                $httpConfig = UpstreamCircuitBreaker::withHttpTimeout($url, $httpConfig);
            }
            $fetch = fn () => $this->fetchTile($url, $cfg['mimeType'], $httpConfig);
            if ($breakerEnabled) {
                $cacheRoot = $res->getMetadata()->getCacheRoot()
                    ?? throw new RuntimeException('The upstream circuit breaker needs a cache root');
                $breaker = new UpstreamCircuitBreaker($cacheRoot, $cfg['upstreamCircuitBreaker']);
                $fetchResult = $breaker->fetch('raster:' . $cfg['cacheServerName'], $url, $fetch);
            } else {
                $fetchResult = $fetch();
            }

            if ($notFound !== null && $fetchResult !== null && $metadata->getLast404() !== null) {
                $metadata->clearLast404();
            }

            if ($fetchResult?->isSuccess()) {
                $res->setData($fetchResult->body);
            } elseif ($notFound !== null && $fetchResult?->statusCode === 404) {
                $now = time();
                if ($notFound->serverTtl > 0) {
                    $metadata->setLast404($now);
                }
                $this->applyNotFoundTtl($res, $notFound->browserTtl($now));
            } elseif ($fetchResult?->isPermanentClientError()) {
                $res->getMetadata()->setLast4xx(time());
            } else {
                // Temporary failure: 5xx, 408/425/429, transport error or timeout, unexpected
                // content type, empty or undecodable body. A stale cached copy is still served;
                // the source is retried after `cacheServer5xxTtl`.
                if ($fetchResult !== null) {
                    $metadata->setLast5xx(time());
                }
                if ($breakerEnabled) {
                    $res->cacheBrowserTtlNotFound = 0;
                }
                $res->cacheBrowserTtl = min($res->cacheBrowserTtl, $cfg['cacheBrowserTtlFail'] ?? 300);
            }
        }

        return $next($res);
    }

    private function applyNotFoundTtl(Result $res, int $ttl): void
    {
        $res->cacheBrowserTtlNotFound = $ttl;
        $res->cacheBrowserTtl = min($res->cacheBrowserTtl, $ttl);
    }

    private function fetchTile(string $url, ?string $mimeType, array $httpConfig): UpstreamFetchResult
    {
        $result = UpstreamFetcher::fetch($url, $httpConfig, $mimeType, $mimeType);
        if ($result->isSuccess() && Utils::isImageMimeType($mimeType) && !Utils::isDecodableImage($result->body)) {
            Log::warning('Upstream image could not be decoded', ['url' => $url, 'mimeType' => $mimeType]);
            return new UpstreamFetchResult(null, $result->statusCode, false, error: 'undecodable image');
        }

        return $result;
    }

    protected function getSrcUrl(array $cfg, Result $res): string
    {
        $reqArgs = $res->getReqArgs();

        if (!empty($cfg['wms']) && is_array($cfg['wms'])) {
            $wms = $cfg['wms'];
            if (!isset($wms['format']) && isset($cfg['mimeType']) && is_string($cfg['mimeType'])) {
                $wms['format'] = $cfg['mimeType'];
            }

            return WmsUrl::fromConfig($wms, $reqArgs);
        }

        if (empty($cfg['urls'])) {
            throw new RuntimeException('No urls configured');
        }

        $url = $cfg['urls'][array_rand($cfg['urls'])];

        return WmsUrl::expandPlaceholders($url, $reqArgs);
    }
}
