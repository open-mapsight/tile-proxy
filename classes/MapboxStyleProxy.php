<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use InvalidArgumentException;
use RuntimeException;
use stdClass;

class MapboxStyleProxy
{
    private const DEFAULT_CACHE_TTLS = [
        'style' => 86400,
        'tilejson' => 86400,
        'tile' => 604800,
        'sprite' => 2592000,
        'glyph' => 2592000,
        'font-css' => 2592000,
        'font' => 2592000,
    ];

    public static function run(array $cfg, string $requestPath): void
    {
        HttpResponse::sendRequest(
            $cfg,
            static fn () => self::handleRequest($cfg, $requestPath)
        );
    }

    private ?int $responseTtlCap = null;

    public static function handleRequest(array $cfg, string $requestPath): HttpResponse
    {
        $proxy = new self();
        try {
            $response = $proxy->routeRequest($cfg, $requestPath);
        } catch (MapAssetUnavailable $e) {
            if ($e->notFoundTtl === null) {
                return new HttpResponse(
                    'Upstream map asset unavailable', 'text/plain', 0, null,
                    statusCode: 503,
                    retryAfter: $e->retryAfter,
                );
            }

            return HttpResponse::missing(min($e->notFoundTtl, $proxy->responseTtlCap ?? PHP_INT_MAX));
        }

        if ($proxy->responseTtlCap === null || $response->cacheBrowserTtl === null) {
            return $response;
        }

        return new HttpResponse(
            $response->body,
            $response->mimeType,
            min($response->cacheBrowserTtl, $proxy->responseTtlCap),
            $response->cacheMTime,
            $response->notModified,
            $response->statusCode,
            $response->retryAfter,
        );
    }

    private function routeRequest(array $cfg, string $requestPath): HttpResponse
    {
        $basePath = Utils::mapAssetBasePath($cfg);
        $routePath = self::stripMapAssetBasePath($requestPath, $basePath);

        if (preg_match('#^styles/([^/]+)\.json$#', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $style = $this->loadUpstreamStyle($cfg, $styleName);
            $style = MapboxStyleTransforms::apply($style, self::getStyleCfg($cfg, $styleName));
            $style = self::rewriteStyle($cfg, $styleName, $style);

            return new HttpResponse(
                json_encode($style, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'application/json',
                self::getCacheTtl(self::getStyleCfg($cfg, $styleName), 'style'),
                null
            );
        }

        if (preg_match('#^tilejson/([^/]+)/([^/]+)\.json$#', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $sourceName = rawurldecode($matches[2]);
            $source = $this->getUpstreamSource($cfg, $styleName, $sourceName);

            if (empty($source['url']) || !is_string($source['url'])) {
                throw new UserException('Source "' . $sourceName . '" does not define a TileJSON URL');
            }

            $styleCfg = self::getStyleCfg($cfg, $styleName);
            $tileJsonUrl = $source['url'];
            $tileJson = $this->loadTileJson($cfg, $styleName, $sourceName, $styleCfg, $tileJsonUrl);
            if (isset($source['attribution']) && is_string($source['attribution'])) {
                $tileJson['attribution'] = $source['attribution'];
            }
            $tileJson = self::rewriteTileJson($cfg, $styleName, $sourceName, $tileJson, $tileJsonUrl);

            return new HttpResponse(
                json_encode($tileJson, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'application/json',
                self::getCacheTtl($styleCfg, 'tilejson'),
                null
            );
        }

        if (preg_match('#^tiles/([^/]+)/([^/]+)/(\d+)/(\d+)/(\d+)/(\d+)\.pbf$#', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $sourceName = rawurldecode($matches[2]);
            $tileIndex = (int)$matches[3];
            $z = $matches[4];
            $x = $matches[5];
            $y = $matches[6];
            $styleCfg = self::getStyleCfg($cfg, $styleName);
            self::assertTileAllowed($styleCfg, (int)$z, (int)$x, (int)$y);
            $tileUrl = $this->getTileTemplate($cfg, $styleName, $sourceName, $tileIndex);
            $tileUrl = str_replace(['{z}', '{x}', '{y}'], [$z, $x, $y], $tileUrl);

            return new HttpResponse(
                $this->fetchWithCache($cfg, $styleName, $styleCfg, 'tile', $tileUrl),
                'application/x-protobuf',
                self::getCacheTtl($styleCfg, 'tile'),
                null
            );
        }

        if (preg_match('#^sprites/([^/]+)/([A-Za-z0-9_-]+)\.(json|png)$#', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $spriteBaseUrl = self::base64UrlDecode($matches[2]);
            $extension = $matches[3];
            $styleCfg = self::getStyleCfg($cfg, $styleName);

            return new HttpResponse(
                $this->fetchWithCache($cfg, $styleName, $styleCfg, 'sprite', $spriteBaseUrl . '.' . $extension),
                $extension === 'png' ? 'image/png' : 'application/json',
                self::getCacheTtl($styleCfg, 'sprite'),
                null
            );
        }

        if (preg_match('#^glyphs/([^/]+)/([A-Za-z0-9_-]+)/(.+)/([^/]+\.pbf)$#', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $glyphTemplate = self::base64UrlDecode($matches[2]);
            $fontstack = rawurldecode($matches[3]);
            $range = rawurldecode(substr($matches[4], 0, -4));
            $styleCfg = self::getStyleCfg($cfg, $styleName);
            $glyphUrl = str_replace(
                ['{fontstack}', '{range}'],
                [rawurlencode($fontstack), rawurlencode($range)],
                $glyphTemplate
            );

            if (str_starts_with($glyphTemplate, 'file://')) {
                $glyphUrl = str_replace(
                    ['{fontstack}', '{range}'],
                    [$fontstack, $range],
                    $glyphTemplate
                );
            }

            return new HttpResponse(
                $this->fetchWithCache($cfg, $styleName, $styleCfg, 'glyph', $glyphUrl),
                'application/x-protobuf',
                self::getCacheTtl($styleCfg, 'glyph'),
                null
            );
        }

        if (preg_match('#^fonts/([^/]+)/([a-z0-9-]+)/([1-9]00)(-italic)?\.css$#D', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $styleCfg = self::getStyleCfg($cfg, $styleName);
            $family = $matches[2];
            $variant = $matches[3] . ($matches[4] ?? '');
            $font = FontsourceFonts::configuration($styleCfg, $family, $variant);
            $css = '';
            if ($font !== null) {
                $url = FontsourceFonts::baseUrl($family, $font) . '/' . $font['variant'] . '.css';
                $rewrite = static fn (string $raw): string => FontsourceFonts::rewriteCss(
                    $raw,
                    $family,
                    $variant,
                    $font,
                    self::publicUrl($cfg, 'fonts/' . rawurlencode($styleName) . '/' . $family)
                );
                $css = $rewrite($this->fetchWithCache($cfg, $styleName, $styleCfg, 'font-css', $url, $rewrite));
            }

            // Unknown faces deliberately fall back locally, never to the client's default CDN.
            return new HttpResponse($css, 'text/css', self::getCacheTtl($styleCfg, 'font-css'), null);
        }

        if (preg_match('#^fonts/([^/]+)/([a-z0-9-]+)/([a-z-]+)-([1-9]00)-(normal|italic)\.woff2$#D', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $styleCfg = self::getStyleCfg($cfg, $styleName);
            [, , $family, $subset, $weight, $fontStyle] = $matches;
            $variant = $weight . ($fontStyle === 'italic' ? '-italic' : '');
            $font = FontsourceFonts::configuration($styleCfg, $family, $variant);
            if ($font === null || !in_array($subset, $font['subsets'], true)) {
                throw new UserException('Font face is not configured');
            }
            $upstreamWeight = substr($font['variant'], 0, 3);
            $upstreamStyle = str_ends_with($font['variant'], '-italic') ? 'italic' : 'normal';
            $url = FontsourceFonts::baseUrl($family, $font) . '/files/' . $family . '-' . $subset . '-' . $upstreamWeight . '-' . $upstreamStyle . '.woff2';

            return new HttpResponse(
                $this->fetchWithCache($cfg, $styleName, $styleCfg, 'font', $url),
                'font/woff2',
                self::getCacheTtl($styleCfg, 'font'),
                null
            );
        }

        throw new UserException('Unsupported map asset route');
    }

    private static function stripMapAssetBasePath(string $requestPath, string $basePath): string
    {
        $path = parse_url($requestPath, PHP_URL_PATH);
        if (!is_string($path)) {
            throw new UserException('Invalid request path');
        }

        if ($basePath !== '') {
            if ($path !== $basePath && !str_starts_with($path, $basePath . '/')) {
                throw new UserException('Request path is outside the configured map asset base path');
            }

            $path = substr($path, strlen($basePath));
        }

        return ltrim($path, '/');
    }

    private static function assertTileAllowed(array $styleCfg, int $z, int $x, int $y): void
    {
        XyzTile::assertValid($z, $x, $y);
        $bounds = self::getTileBounds($styleCfg);
        if ($bounds === null) {
            return;
        }
        [$west, $south, $east, $north] = XyzTile::bbox4326($z, $x, $y);
        if ($east <= $bounds[0] || $west >= $bounds[2] || $north <= $bounds[1] || $south >= $bounds[3]) {
            throw new UserException('Tile is outside the configured tileBounds');
        }
    }

    /**
     * @return array{float, float, float, float}|null west, south, east, north in EPSG:4326
     */
    private static function getTileBounds(array $styleCfg): ?array
    {
        if (!isset($styleCfg['tileBounds'])) {
            return null;
        }
        $bounds = $styleCfg['tileBounds'];
        if (!is_array($bounds) || array_keys($bounds) !== [0, 1, 2, 3]
            || count(array_filter($bounds, static fn ($n): bool => is_numeric($n) && is_finite((float)$n))) !== 4) {
            throw new RuntimeException('Invalid tileBounds: expected [west, south, east, north]');
        }
        $bounds = array_map('floatval', $bounds);
        if ($bounds[0] >= $bounds[2] || $bounds[1] >= $bounds[3]
            || $bounds[0] < -180 || $bounds[2] > 180 || $bounds[1] < -XyzTile::MAX_LATITUDE || $bounds[3] > XyzTile::MAX_LATITUDE) {
            throw new RuntimeException('Invalid tileBounds: expected west < east and south < north within Web Mercator, without antimeridian crossing');
        }

        return $bounds;
    }

    /**
     * Narrows the bounds a TileJSON document or style source advertises to the configured tileBounds,
     * so clients do not request tiles the proxy would reject.
     */
    private static function restrictAdvertisedBounds(array $styleCfg, array $document, string $what): array
    {
        $bounds = self::getTileBounds($styleCfg);
        if ($bounds === null) {
            return $document;
        }
        $original = $document['bounds'] ?? null;
        if (!is_array($original) || array_keys($original) !== [0, 1, 2, 3]) {
            $original = [-180, -XyzTile::MAX_LATITUDE, 180, XyzTile::MAX_LATITUDE];
        }
        $document['bounds'] = [
            max($bounds[0], (float)$original[0]),
            max($bounds[1], (float)$original[1]),
            min($bounds[2], (float)$original[2]),
            min($bounds[3], (float)$original[3]),
        ];
        if ($document['bounds'][0] >= $document['bounds'][2] || $document['bounds'][1] >= $document['bounds'][3]) {
            throw new RuntimeException('tileBounds does not intersect the bounds of ' . $what);
        }

        return $document;
    }

    private static function rewriteStyle(array $cfg, string $styleName, array $style): array
    {
        $styleCfg = self::getStyleCfg($cfg, $styleName);
        $styleUrl = self::getUpstreamStyleUrl($styleCfg, $styleName);

        if (($styleCfg['olWebfonts'] ?? false) === true) {
            // Only opt-in styles receive OL metadata, including when all downloads are disabled.
            // applyStyle() treats false metadata as absent, so disabled fonts still need this local template.
            $style['metadata']['ol:webfonts'] = self::publicUrl(
                $cfg,
                'fonts/' . rawurlencode($styleName) . '/{font-family}/{fontweight}{-fontstyle}.css'
            );
        }

        if (isset($styleCfg['attribution'])) {
            $style['metadata'] ??= [];
            $style['metadata']['mapsight:attribution'] = $styleCfg['attribution'];
        }

        if (!empty($style['sources']) && is_array($style['sources'])) {
            foreach ($style['sources'] as $sourceName => $source) {
                if (!is_array($source)) {
                    continue;
                }

                if (!empty($source['url']) && is_string($source['url'])) {
                    UpstreamUrlPolicy::assertAllowedUpstreamUrl($styleCfg, UpstreamUrlPolicy::resolveUrl($source['url'], $styleUrl));
                    $style['sources'][$sourceName]['url'] = self::publicUrl(
                        $cfg,
                        'tilejson/' . rawurlencode((string)$styleName) . '/' . rawurlencode((string)$sourceName) . '.json'
                    );
                }

                if (!empty($source['tiles']) && is_array($source['tiles'])) {
                    $style['sources'][$sourceName] = self::restrictAdvertisedBounds(
                        $styleCfg,
                        $style['sources'][$sourceName],
                        'source "' . $sourceName . '"'
                    );
                    $style['sources'][$sourceName]['tiles'] = self::rewriteTileTemplates(
                        $cfg,
                        $styleName,
                        (string)$sourceName,
                        $source['tiles'],
                        $styleUrl
                    );
                }
            }
        }

        if (!empty($style['sprite']) && is_string($style['sprite'])) {
            $spriteUrl = UpstreamUrlPolicy::resolveUrl($style['sprite'], $styleUrl);
            UpstreamUrlPolicy::assertAllowedUpstreamUrl($styleCfg, $spriteUrl);
            $style['sprite'] = self::publicUrl(
                $cfg,
                'sprites/' . rawurlencode($styleName) . '/' . self::base64UrlEncode($spriteUrl)
            );
        }

        if (!empty($style['glyphs']) && is_string($style['glyphs'])) {
            $glyphUrl = UpstreamUrlPolicy::resolveUrl($style['glyphs'], $styleUrl);
            UpstreamUrlPolicy::assertAllowedUpstreamUrl($styleCfg, $glyphUrl);
            $style['glyphs'] = self::publicUrl(
                $cfg,
                'glyphs/' . rawurlencode($styleName) . '/' . self::base64UrlEncode($glyphUrl) . '/{fontstack}/{range}.pbf'
            );
        }

        return $style;
    }

    private static function rewriteTileJson(array $cfg, string $styleName, string $sourceName, array $tileJson, string $tileJsonUrl): array
    {
        $tileJson = self::restrictAdvertisedBounds(self::getStyleCfg($cfg, $styleName), $tileJson, 'the upstream TileJSON');
        if (!empty($tileJson['tiles']) && is_array($tileJson['tiles'])) {
            $tileJson['tiles'] = self::rewriteTileTemplates($cfg, $styleName, $sourceName, $tileJson['tiles'], $tileJsonUrl);
        }

        return $tileJson;
    }

    private static function rewriteTileTemplates(
        array $cfg,
        string $styleName,
        string $sourceName,
        array $tileTemplates,
        string $baseUrl
    ): array
    {
        $styleCfg = self::getStyleCfg($cfg, $styleName);
        $rewritten = [];

        foreach (array_values($tileTemplates) as $tileIndex => $tileTemplate) {
            if (!is_string($tileTemplate)) {
                continue;
            }

            UpstreamUrlPolicy::assertAllowedUpstreamUrl($styleCfg, UpstreamUrlPolicy::resolveUrl($tileTemplate, $baseUrl));
            $rewritten[] = self::publicUrl(
                $cfg,
                'tiles/' . rawurlencode($styleName) . '/' . rawurlencode($sourceName) . '/' . $tileIndex . '/{z}/{x}/{y}.pbf'
            );
        }

        return $rewritten;
    }

    private function getTileTemplate(array $cfg, string $styleName, string $sourceName, int $tileIndex): string
    {
        $source = $this->getUpstreamSource($cfg, $styleName, $sourceName);

        if (!empty($source['tiles']) && is_array($source['tiles'])) {
            if (!isset(array_values($source['tiles'])[$tileIndex])) {
                throw new UserException('Tile template index is not configured');
            }

            return array_values($source['tiles'])[$tileIndex];
        }

        if (!empty($source['url']) && is_string($source['url'])) {
            $styleCfg = self::getStyleCfg($cfg, $styleName);
            $tileJson = $this->loadTileJson($cfg, $styleName, $sourceName, $styleCfg, $source['url']);
            if (empty($tileJson['tiles']) || !is_array($tileJson['tiles']) || !isset(array_values($tileJson['tiles'])[$tileIndex])) {
                throw new UserException('TileJSON does not define the requested tile template');
            }

            return UpstreamUrlPolicy::resolveUrl(array_values($tileJson['tiles'])[$tileIndex], $source['url']);
        }

        throw new UserException('Source "' . $sourceName . '" does not define vector tiles');
    }

    private function getUpstreamSource(array $cfg, string $styleName, string $sourceName): array
    {
        $style = $this->loadUpstreamStyle($cfg, $styleName);
        $styleCfg = self::getStyleCfg($cfg, $styleName);
        $styleUrl = self::getUpstreamStyleUrl($styleCfg, $styleName);

        if (empty($style['sources'][$sourceName]) || !is_array($style['sources'][$sourceName])) {
            throw new UserException('Source "' . $sourceName . '" is not configured');
        }

        $source = $style['sources'][$sourceName];
        if (!empty($source['url']) && is_string($source['url'])) {
            $source['url'] = UpstreamUrlPolicy::resolveUrl($source['url'], $styleUrl);
        }

        if (!empty($source['tiles']) && is_array($source['tiles'])) {
            $source['tiles'] = array_map(
                static fn ($tileUrl) => is_string($tileUrl) ? UpstreamUrlPolicy::resolveUrl($tileUrl, $styleUrl) : $tileUrl,
                $source['tiles']
            );
        }

        return $source;
    }

    private function loadUpstreamStyle(array $cfg, string $styleName): array
    {
        $styleCfg = self::getStyleCfg($cfg, $styleName);
        $styleUrl = self::getUpstreamStyleUrl($styleCfg, $styleName);

        self::getTileBounds($styleCfg);
        $validate = static function (string $body) use ($cfg, $styleName, $styleCfg): void {
            $style = MapboxStyleTransforms::apply(self::decodeJson($body), $styleCfg);
            self::rewriteStyle($cfg, $styleName, $style);
        };

        return self::decodeJson($this->fetchWithCache($cfg, $styleName, $styleCfg, 'style', $styleUrl, $validate));
    }

    private function loadTileJson(
        array $cfg,
        string $styleName,
        string $sourceName,
        array $styleCfg,
        string $url,
    ): array {
        $validate = static function (string $body) use ($cfg, $styleName, $sourceName, $url): void {
            self::rewriteTileJson($cfg, $styleName, $sourceName, self::decodeJson($body), $url);
        };

        return self::decodeJson($this->fetchWithCache($cfg, $styleName, $styleCfg, 'tilejson', $url, $validate));
    }

    private static function getUpstreamStyleUrl(array $styleCfg, string $styleName): string
    {
        if (empty($styleCfg['upstreamStyleUrl']) || !is_string($styleCfg['upstreamStyleUrl'])) {
            throw new RuntimeException('Missing upstreamStyleUrl for style "' . $styleName . '"');
        }

        return $styleCfg['upstreamStyleUrl'];
    }

    private function fetchWithCache(
        array $cfg,
        string $styleName,
        array $styleCfg,
        string $resourceType,
        string $upstreamUrl,
        ?callable $validate = null,
    ): string {
        UpstreamUrlPolicy::assertAllowedUpstreamUrl($styleCfg, $upstreamUrl);

        $cachePath = self::getCachePath($cfg, $styleName, $resourceType, $upstreamUrl);
        $cacheTtl = self::getCacheTtl($styleCfg, $resourceType);
        $failureConfig = $styleCfg['upstreamFailureCache'] ?? null;
        $breakerConfig = $styleCfg['upstreamCircuitBreaker'] ?? null;
        $resilienceEnabled = $failureConfig !== null || $breakerConfig !== null;
        $notFound = $resourceType === 'tile' ? TileNotFoundPolicy::fromConfig($styleCfg) : null;
        $cacheKind = self::cacheKind($resourceType, $upstreamUrl);
        $validationError = static function (string $body) use ($cacheKind, $validate): ?string {
            if (!self::isUsableAsset($body, $cacheKind)) {
                return 'Invalid upstream map asset body';
            }

            try {
                if ($validate !== null) {
                    $validate($body);
                }
                return null;
            } catch (RuntimeException $error) {
                return $error->getMessage();
            }
        };

        if ($breakerConfig !== null) {
            $cfg['upstreamHttp'] = UpstreamCircuitBreaker::withHttpTimeout($upstreamUrl, $cfg['upstreamHttp'] ?? []);
        }
        $fetch = static fn () => self::fetchAsset($cfg, $styleCfg, $resourceType, $upstreamUrl, $validationError);
        $cached = DiskCache::readFresh($cachePath, $cacheTtl);
        if ($cached !== null && $validationError($cached) === null) {
            return $cached;
        }

        if (!$resilienceEnabled && $notFound === null) {
            $result = $fetch();
            if ($result->isSuccess()) {
                self::writeCacheBestEffort($cachePath, $result->body, $resourceType);
                return $result->body;
            }

            $stale = DiskCache::read($cachePath);
            if ($stale !== null && $validationError($stale) === null) {
                return $stale;
            }

            $reason = !$result->transportFailed && !$result->contentTypeMismatch ? $result->error : null;
            throw new UserException($reason ?? 'Could not fetch upstream map asset');
        }

        if ($failureConfig !== null && !is_array($failureConfig)) {
            throw new InvalidArgumentException('upstreamFailureCache must be an object');
        }

        $cooldowns = [
            'permanent' => $failureConfig === null ? 0 : self::failureTtl($failureConfig, 'cacheServer4xxTtl', 3600),
            'temporary' => $failureConfig === null ? 0 : self::failureTtl($failureConfig, 'cacheServer5xxTtl', 300),
            'notFound' => $notFound->serverTtl ?? 0,
        ];
        $failureBrowserTtl = self::failureTtl($failureConfig, 'cacheBrowserTtlFail', 300);
        $breaker = $breakerConfig === null ? null : new UpstreamCircuitBreaker($cfg['cacheServerPath'], $breakerConfig);
        // A bounded pool avoids one permanent lock file for every requested tile.
        $lockPath = rtrim($cfg['cacheServerPath'], '/') . '/mapbox-style-proxy/.locks/'
            . substr(hash('sha256', serialize([$styleName, $resourceType, $upstreamUrl])), 0, 2) . '.lock';
        try {
            $lock = FileLock::acquire($lockPath, (float)($cfg['cacheLockTimeout'] ?? 2.85));
        } catch (RuntimeException) {
            Log::warning('Could not lock Mapbox asset cache', ['resourceType' => $resourceType]);
            $lock = null;
        }
        if ($lock === null) {
            return $this->serveFailedAsset($cachePath, $validationError, temporaryTtl: $failureBrowserTtl);
        }

        try {
            $cached = DiskCache::readFresh($cachePath, $cacheTtl);
            if ($cached !== null && $validationError($cached) === null) {
                return $cached;
            }

            $failurePath = $cachePath . '.failure.json';
            $failure = json_decode(DiskCache::read($failurePath) ?? '', true);
            $kind = $failure['kind'] ?? null;
            $at = $failure['at'] ?? null;
            if (is_int($at) && $at >= 0 && is_string($kind) && isset($cooldowns[$kind])) {
                $remaining = $cooldowns[$kind] - max(0, time() - $at);
                if ($remaining > 0) {
                    return $this->serveFailedAsset(
                        $cachePath,
                        $validationError,
                        $kind === 'temporary' ? $failureBrowserTtl : null,
                        $kind === 'notFound' ? $notFound?->browserTtl($at) : null,
                        $kind === 'temporary' ? $remaining : null,
                    );
                }
            }

            $result = $breaker === null ? $fetch() : $breaker->fetch('mapbox:' . $styleName, $upstreamUrl, $fetch);
            if ($result?->isSuccess()) {
                self::writeCacheBestEffort($cachePath, $result->body, $resourceType);
                if ($failure !== null) {
                    @unlink($failurePath);
                }
                return $result->body;
            }

            $kind = match (true) {
                $notFound !== null && $result?->statusCode === 404 => 'notFound',
                $result?->isPermanentClientError() === true => 'permanent',
                default => 'temporary',
            };
            $now = time();
            $savedCooldown = false;
            if ($result !== null) {
                if ($cooldowns[$kind] > 0) {
                    $savedCooldown = self::writeCacheBestEffort(
                        $failurePath,
                        json_encode(['kind' => $kind, 'at' => $now], JSON_THROW_ON_ERROR),
                        $resourceType,
                    );
                } elseif ($failure !== null) {
                    @unlink($failurePath);
                }
            }

            return $this->serveFailedAsset(
                $cachePath,
                $validationError,
                $kind === 'temporary' && $resilienceEnabled ? $failureBrowserTtl : null,
                $kind === 'notFound' ? $notFound?->browserTtl($now) : null,
                $kind === 'temporary' && $savedCooldown ? max(0, $cooldowns[$kind] - max(0, time() - $now)) : null,
            );
        } finally {
            $lock->release();
        }
    }

    private function serveFailedAsset(
        string $cachePath,
        callable $validationError,
        ?int $temporaryTtl,
        ?int $notFoundTtl = null,
        ?int $retryAfter = null,
    ): string {
        $stale = DiskCache::read($cachePath);
        $cap = $notFoundTtl ?? $temporaryTtl;
        if ($stale === null || $validationError($stale) !== null) {
            if ($cap !== null) {
                throw new MapAssetUnavailable($notFoundTtl, $retryAfter);
            }
            throw new UserException('Could not fetch upstream map asset');
        }

        if ($cap !== null) {
            $this->responseTtlCap = min($this->responseTtlCap ?? PHP_INT_MAX, $cap);
        }

        return $stale;
    }

    private static function writeCacheBestEffort(string $path, string $body, string $resourceType): bool
    {
        try {
            DiskCache::writeAtomically($path, $body);
            return true;
        } catch (RuntimeException) {
            Log::warning('Could not write Mapbox asset cache', ['resourceType' => $resourceType]);
            return false;
        }
    }

    private static function failureTtl(?array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException("upstreamFailureCache.$key must be a non-negative integer");
        }
        return $value;
    }

    private static function fetchAsset(
        array $cfg,
        array $styleCfg,
        string $resourceType,
        string $url,
        callable $validationError,
    ): UpstreamFetchResult {
        $expectedContentType = self::expectedContentTypeFor($resourceType, $url);
        $result = UpstreamFetcher::fetch(
            $url,
            $cfg['upstreamHttp'] ?? [],
            $expectedContentType,
            $expectedContentType,
            self::alternateContentTypesFor($cfg, $styleCfg, $resourceType)
        );

        if ($result->isSuccess()) {
            $error = $result->statusCode === 200 ? $validationError($result->body) : 'Unexpected upstream map asset status';
            if ($error !== null) {
                Log::warning('Upstream map asset response is unusable', ['resourceType' => $resourceType]);
                return new UpstreamFetchResult(null, $result->statusCode, false, error: $error);
            }
        }

        return $result;
    }

    private static function cacheKind(string $resourceType, string $url): string
    {
        if ($resourceType === 'sprite') {
            return str_ends_with($url, '.png') ? 'sprite-png' : 'sprite-json';
        }

        return $resourceType;
    }

    private static function isUsableAsset(string $body, string $cacheKind): bool
    {
        if ($body === '') {
            return false;
        }
        if ($cacheKind === 'sprite-png') {
            $image = @getimagesizefromstring($body);
            return ($image[2] ?? null) === IMAGETYPE_PNG && Utils::isDecodableImage($body);
        }
        if (!in_array($cacheKind, ['style', 'tilejson', 'sprite-json'], true)) {
            return true;
        }

        $json = json_decode($body);
        if (!$json instanceof stdClass) {
            return false;
        }

        return match ($cacheKind) {
            'style' => in_array($json->version ?? null, [8, 8.0], true)
                && self::hasUsableStyleSources($json->sources ?? null)
                && is_array($json->layers ?? null)
                && array_filter($json->layers, self::isUsableStyleLayer(...)) === $json->layers
                && (!isset($json->metadata) || $json->metadata instanceof stdClass),
            'tilejson' => self::hasTileUrls($json->tiles ?? null),
            'sprite-json' => self::isSpriteIndex($json),
        };
    }

    private static function isUsableStyleLayer(mixed $layer): bool
    {
        return $layer instanceof stdClass && is_string($layer->id ?? null)
            && (!isset($layer->paint) || $layer->paint instanceof stdClass)
            && (!isset($layer->layout) || $layer->layout instanceof stdClass);
    }

    private static function hasUsableStyleSources(mixed $sources): bool
    {
        if (!$sources instanceof stdClass) {
            return false;
        }

        foreach (get_object_vars($sources) as $source) {
            if (!$source instanceof stdClass
                || (property_exists($source, 'url') && (!is_string($source->url) || $source->url === ''))
                || (property_exists($source, 'tiles') && !self::hasTileUrls($source->tiles))
                || (($source->type ?? null) === 'vector'
                    && !isset($source->url) && !isset($source->tiles))
            ) {
                return false;
            }
        }

        return true;
    }

    private static function hasTileUrls(mixed $tiles): bool
    {
        return is_array($tiles) && $tiles !== []
            && array_filter($tiles, static fn ($tile) => is_string($tile) && $tile !== '') === $tiles;
    }

    private static function isSpriteIndex(stdClass $json): bool
    {
        foreach (get_object_vars($json) as $sprite) {
            if (!$sprite instanceof stdClass) {
                return false;
            }

            foreach (['x', 'y', 'width', 'height'] as $field) {
                if (!is_int($sprite->$field ?? null) || $sprite->$field < 0) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function expectedContentTypeFor(string $resourceType, string $upstreamUrl): ?string
    {
        return match ($resourceType) {
            'style', 'tilejson' => 'application/json',
            'tile', 'glyph' => 'application/x-protobuf',
            'font-css' => 'text/css',
            'font' => 'font/woff2',
            'sprite' => str_ends_with($upstreamUrl, '.png') ? 'image/png' : 'application/json',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private static function alternateContentTypesFor(array $cfg, array $styleCfg, string $resourceType): array
    {
        $lax = $styleCfg['laxContentTypes'] ?? $cfg['laxContentTypes'] ?? false;

        if (is_array($lax)) {
            return array_values(array_filter($lax, 'is_string'));
        }

        if ($lax !== true) {
            return [];
        }

        return match ($resourceType) {
            'tile', 'glyph' => ['application/octet-stream'],
            default => [],
        };
    }

    private static function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new UserException('Upstream JSON response must be an object');
        }

        return $decoded;
    }

    private static function publicUrl(array $cfg, string $route): string
    {
        $basePath = Utils::mapAssetBasePath($cfg);

        return $basePath . '/' . ltrim($route, '/');
    }

    private static function getStyleCfg(array $cfg, string $styleName): array
    {
        if (empty($cfg['styles'][$styleName]) || !is_array($cfg['styles'][$styleName])) {
            throw new UserException('Style "' . $styleName . '" is not configured');
        }

        return $cfg['styles'][$styleName];
    }

    private static function getCacheTtl(array $styleCfg, string $resourceType): int
    {
        return (int)($styleCfg['cacheTtls'][$resourceType] ?? self::DEFAULT_CACHE_TTLS[$resourceType]);
    }

    private static function getCachePath(array $cfg, string $styleName, string $resourceType, string $upstreamUrl): string
    {
        if (empty($cfg['cacheServerPath']) || !is_string($cfg['cacheServerPath'])) {
            throw new RuntimeException('Missing cacheServerPath');
        }

        return rtrim($cfg['cacheServerPath'], '/')
            . '/mapbox-style-proxy/'
            . rawurlencode($styleName)
            . '/'
            . $resourceType
            . '/'
            . sha1($upstreamUrl);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new UserException('Invalid encoded asset URL');
        }

        return $decoded;
    }
}
