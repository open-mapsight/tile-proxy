<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use RuntimeException;

class MapboxStyleProxy
{
    private const DEFAULT_CACHE_TTLS = [
        'style' => 86400,
        'tilejson' => 86400,
        'tile' => 604800,
        'sprite' => 2592000,
        'glyph' => 2592000,
    ];

    public static function handleRequest(array $cfg, string $requestPath): MapboxStyleProxyResponse
    {
        Log::configureFromConfig($cfg);

        $publicBasePath = rtrim((string)($cfg['publicBasePath'] ?? ''), '/');
        $routePath = self::stripPublicBasePath($requestPath, $publicBasePath);

        if (preg_match('#^styles/([^/]+)\.json$#', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $style = self::loadUpstreamStyle($cfg, $styleName);
            $style = MapboxStyleTransforms::apply($style, self::getStyleCfg($cfg, $styleName));
            $style = self::rewriteStyle($cfg, $styleName, $style);

            return new MapboxStyleProxyResponse(
                json_encode($style, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'application/json',
                self::getCacheTtl(self::getStyleCfg($cfg, $styleName), 'style')
            );
        }

        if (preg_match('#^tilejson/([^/]+)/([^/]+)\.json$#', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $sourceName = rawurldecode($matches[2]);
            $source = self::getUpstreamSource($cfg, $styleName, $sourceName);

            if (empty($source['url']) || !is_string($source['url'])) {
                throw new UserException('Source "' . $sourceName . '" does not define a TileJSON URL');
            }

            $styleCfg = self::getStyleCfg($cfg, $styleName);
            $tileJsonUrl = $source['url'];
            $tileJson = self::decodeJson(self::fetchWithCache($cfg, $styleName, $styleCfg, 'tilejson', $tileJsonUrl));
            $tileJson = self::rewriteTileJson($cfg, $styleName, $sourceName, $tileJson, $tileJsonUrl);

            return new MapboxStyleProxyResponse(
                json_encode($tileJson, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'application/json',
                self::getCacheTtl($styleCfg, 'tilejson')
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
            $tileUrl = self::getTileTemplate($cfg, $styleName, $sourceName, $tileIndex);
            $tileUrl = str_replace(['{z}', '{x}', '{y}'], [$z, $x, $y], $tileUrl);

            return new MapboxStyleProxyResponse(
                self::fetchWithCache($cfg, $styleName, $styleCfg, 'tile', $tileUrl),
                'application/x-protobuf',
                self::getCacheTtl($styleCfg, 'tile')
            );
        }

        if (preg_match('#^sprites/([^/]+)/([A-Za-z0-9_-]+)\.(json|png)$#', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $spriteBaseUrl = self::base64UrlDecode($matches[2]);
            $extension = $matches[3];
            $styleCfg = self::getStyleCfg($cfg, $styleName);

            return new MapboxStyleProxyResponse(
                self::fetchWithCache($cfg, $styleName, $styleCfg, 'sprite', $spriteBaseUrl . '.' . $extension),
                $extension === 'png' ? 'image/png' : 'application/json',
                self::getCacheTtl($styleCfg, 'sprite')
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

            return new MapboxStyleProxyResponse(
                self::fetchWithCache($cfg, $styleName, $styleCfg, 'glyph', $glyphUrl),
                'application/x-protobuf',
                self::getCacheTtl($styleCfg, 'glyph')
            );
        }

        throw new UserException('Unsupported map asset route');
    }

    private static function stripPublicBasePath(string $requestPath, string $publicBasePath): string
    {
        $path = parse_url($requestPath, PHP_URL_PATH);
        if (!is_string($path)) {
            throw new UserException('Invalid request path');
        }

        if ($publicBasePath !== '') {
            if ($path !== $publicBasePath && !str_starts_with($path, $publicBasePath . '/')) {
                throw new UserException('Request path is outside the configured map asset base path');
            }

            $path = substr($path, strlen($publicBasePath));
        }

        return ltrim($path, '/');
    }

    private static function rewriteStyle(array $cfg, string $styleName, array $style): array
    {
        $styleCfg = self::getStyleCfg($cfg, $styleName);
        $styleUrl = self::getUpstreamStyleUrl($styleCfg, $styleName);

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

    private static function getTileTemplate(array $cfg, string $styleName, string $sourceName, int $tileIndex): string
    {
        $source = self::getUpstreamSource($cfg, $styleName, $sourceName);

        if (!empty($source['tiles']) && is_array($source['tiles'])) {
            if (!isset(array_values($source['tiles'])[$tileIndex])) {
                throw new UserException('Tile template index is not configured');
            }

            return array_values($source['tiles'])[$tileIndex];
        }

        if (!empty($source['url']) && is_string($source['url'])) {
            $styleCfg = self::getStyleCfg($cfg, $styleName);
            $tileJson = self::decodeJson(self::fetchWithCache($cfg, $styleName, $styleCfg, 'tilejson', $source['url']));
            if (empty($tileJson['tiles']) || !is_array($tileJson['tiles']) || !isset(array_values($tileJson['tiles'])[$tileIndex])) {
                throw new UserException('TileJSON does not define the requested tile template');
            }

            return UpstreamUrlPolicy::resolveUrl(array_values($tileJson['tiles'])[$tileIndex], $source['url']);
        }

        throw new UserException('Source "' . $sourceName . '" does not define vector tiles');
    }

    private static function getUpstreamSource(array $cfg, string $styleName, string $sourceName): array
    {
        $style = self::loadUpstreamStyle($cfg, $styleName);
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

    private static function loadUpstreamStyle(array $cfg, string $styleName): array
    {
        $styleCfg = self::getStyleCfg($cfg, $styleName);
        $styleUrl = self::getUpstreamStyleUrl($styleCfg, $styleName);

        return self::decodeJson(self::fetchWithCache($cfg, $styleName, $styleCfg, 'style', $styleUrl));
    }

    private static function getUpstreamStyleUrl(array $styleCfg, string $styleName): string
    {
        if (empty($styleCfg['upstreamStyleUrl']) || !is_string($styleCfg['upstreamStyleUrl'])) {
            throw new RuntimeException('Missing upstreamStyleUrl for style "' . $styleName . '"');
        }

        return $styleCfg['upstreamStyleUrl'];
    }

    private static function fetchWithCache(
        array $cfg,
        string $styleName,
        array $styleCfg,
        string $resourceType,
        string $upstreamUrl
    ): string {
        UpstreamUrlPolicy::assertAllowedUpstreamUrl($styleCfg, $upstreamUrl);

        $cachePath = self::getCachePath($cfg, $styleName, $resourceType, $upstreamUrl);
        $cacheTtl = self::getCacheTtl($styleCfg, $resourceType);
        $expectedContentType = self::expectedContentTypeFor($resourceType, $upstreamUrl);

        $cached = DiskCache::readFresh($cachePath, $cacheTtl);
        if ($cached !== null) {
            return $cached;
        }

        $fetchResult = UpstreamFetcher::fetch(
            $upstreamUrl,
            $cfg['upstreamHttp'] ?? [],
            $expectedContentType,
            $expectedContentType
        );
        if (!$fetchResult->isSuccess()) {
            $stale = DiskCache::read($cachePath);
            if ($stale !== null) {
                return $stale;
            }

            throw new UserException('Could not fetch upstream map asset');
        }

        DiskCache::write($cachePath, $fetchResult->body);

        return $fetchResult->body;
    }

    private static function expectedContentTypeFor(string $resourceType, string $upstreamUrl): ?string
    {
        return match ($resourceType) {
            'style', 'tilejson' => 'application/json',
            'tile', 'glyph' => 'application/x-protobuf',
            'sprite' => str_ends_with($upstreamUrl, '.png') ? 'image/png' : 'application/json',
            default => null,
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
        $publicBasePath = rtrim((string)($cfg['publicBasePath'] ?? ''), '/');

        return $publicBasePath . '/' . ltrim($route, '/');
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
