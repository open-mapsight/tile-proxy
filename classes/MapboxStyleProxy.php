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
        $publicBasePath = rtrim((string)($cfg['publicBasePath'] ?? ''), '/');
        $routePath = static::stripPublicBasePath($requestPath, $publicBasePath);

        if (preg_match('#^styles/([^/]+)\.json$#', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $style = static::loadUpstreamStyle($cfg, $styleName);
            $style = static::applyStyleTransforms($style, static::getStyleCfg($cfg, $styleName));
            $style = static::rewriteStyle($cfg, $styleName, $style);

            return new MapboxStyleProxyResponse(
                json_encode($style, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'application/json',
                static::getCacheTtl(static::getStyleCfg($cfg, $styleName), 'style')
            );
        }

        if (preg_match('#^tilejson/([^/]+)/([^/]+)\.json$#', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $sourceName = rawurldecode($matches[2]);
            $source = static::getUpstreamSource($cfg, $styleName, $sourceName);

            if (empty($source['url']) || !is_string($source['url'])) {
                throw new UserException('Source "' . $sourceName . '" does not define a TileJSON URL');
            }

            $styleCfg = static::getStyleCfg($cfg, $styleName);
            $tileJsonUrl = $source['url'];
            $tileJson = static::decodeJson(static::fetchWithCache($cfg, $styleName, $styleCfg, 'tilejson', $tileJsonUrl));
            $tileJson = static::rewriteTileJson($cfg, $styleName, $sourceName, $tileJson, $tileJsonUrl);

            return new MapboxStyleProxyResponse(
                json_encode($tileJson, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'application/json',
                static::getCacheTtl($styleCfg, 'tilejson')
            );
        }

        if (preg_match('#^tiles/([^/]+)/([^/]+)/(\d+)/(\d+)/(\d+)/(\d+)\.pbf$#', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $sourceName = rawurldecode($matches[2]);
            $tileIndex = (int)$matches[3];
            $z = $matches[4];
            $x = $matches[5];
            $y = $matches[6];
            $styleCfg = static::getStyleCfg($cfg, $styleName);
            $tileUrl = static::getTileTemplate($cfg, $styleName, $sourceName, $tileIndex);
            $tileUrl = str_replace(['{z}', '{x}', '{y}'], [$z, $x, $y], $tileUrl);

            return new MapboxStyleProxyResponse(
                static::fetchWithCache($cfg, $styleName, $styleCfg, 'tile', $tileUrl),
                'application/x-protobuf',
                static::getCacheTtl($styleCfg, 'tile')
            );
        }

        if (preg_match('#^sprites/([^/]+)/([A-Za-z0-9_-]+)\.(json|png)$#', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $spriteBaseUrl = static::base64UrlDecode($matches[2]);
            $extension = $matches[3];
            $styleCfg = static::getStyleCfg($cfg, $styleName);

            return new MapboxStyleProxyResponse(
                static::fetchWithCache($cfg, $styleName, $styleCfg, 'sprite', $spriteBaseUrl . '.' . $extension),
                $extension === 'png' ? 'image/png' : 'application/json',
                static::getCacheTtl($styleCfg, 'sprite')
            );
        }

        if (preg_match('#^glyphs/([^/]+)/([A-Za-z0-9_-]+)/(.+)/([^/]+\.pbf)$#', $routePath, $matches) === 1) {
            $styleName = rawurldecode($matches[1]);
            $glyphTemplate = static::base64UrlDecode($matches[2]);
            $fontstack = rawurldecode($matches[3]);
            $range = rawurldecode(substr($matches[4], 0, -4));
            $styleCfg = static::getStyleCfg($cfg, $styleName);
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
                static::fetchWithCache($cfg, $styleName, $styleCfg, 'glyph', $glyphUrl),
                'application/x-protobuf',
                static::getCacheTtl($styleCfg, 'glyph')
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
        $styleCfg = static::getStyleCfg($cfg, $styleName);
        $styleUrl = static::getUpstreamStyleUrl($styleCfg, $styleName);

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
                    static::assertAllowedUpstreamUrl($styleCfg, static::resolveUrl($source['url'], $styleUrl));
                    $style['sources'][$sourceName]['url'] = static::publicUrl(
                        $cfg,
                        'tilejson/' . rawurlencode((string)$styleName) . '/' . rawurlencode((string)$sourceName) . '.json'
                    );
                }

                if (!empty($source['tiles']) && is_array($source['tiles'])) {
                    $style['sources'][$sourceName]['tiles'] = static::rewriteTileTemplates(
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
            $spriteUrl = static::resolveUrl($style['sprite'], $styleUrl);
            static::assertAllowedUpstreamUrl($styleCfg, $spriteUrl);
            $style['sprite'] = static::publicUrl(
                $cfg,
                'sprites/' . rawurlencode($styleName) . '/' . static::base64UrlEncode($spriteUrl)
            );
        }

        if (!empty($style['glyphs']) && is_string($style['glyphs'])) {
            $glyphUrl = static::resolveUrl($style['glyphs'], $styleUrl);
            static::assertAllowedUpstreamUrl($styleCfg, $glyphUrl);
            $style['glyphs'] = static::publicUrl(
                $cfg,
                'glyphs/' . rawurlencode($styleName) . '/' . static::base64UrlEncode($glyphUrl) . '/{fontstack}/{range}.pbf'
            );
        }

        return $style;
    }

    private static function rewriteTileJson(array $cfg, string $styleName, string $sourceName, array $tileJson, string $tileJsonUrl): array
    {
        if (!empty($tileJson['tiles']) && is_array($tileJson['tiles'])) {
            $tileJson['tiles'] = static::rewriteTileTemplates($cfg, $styleName, $sourceName, $tileJson['tiles'], $tileJsonUrl);
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
        $styleCfg = static::getStyleCfg($cfg, $styleName);
        $rewritten = [];

        foreach (array_values($tileTemplates) as $tileIndex => $tileTemplate) {
            if (!is_string($tileTemplate)) {
                continue;
            }

            static::assertAllowedUpstreamUrl($styleCfg, static::resolveUrl($tileTemplate, $baseUrl));
            $rewritten[] = static::publicUrl(
                $cfg,
                'tiles/' . rawurlencode($styleName) . '/' . rawurlencode($sourceName) . '/' . $tileIndex . '/{z}/{x}/{y}.pbf'
            );
        }

        return $rewritten;
    }

    private static function applyStyleTransforms(array $style, array $styleCfg): array
    {
        foreach (($styleCfg['transforms'] ?? []) as $transform) {
            if (!is_array($transform)) {
                continue;
            }

            $style = match ($transform['op'] ?? null) {
                'removeLayersById' => static::removeLayersById($style, $transform['ids'] ?? []),
                'removeLayersByIdContains' => static::removeLayersByIdContains($style, (string)($transform['contains'] ?? '')),
                'removeLayersByIdPrefixExcept' => static::removeLayersByIdPrefixExcept(
                    $style,
                    $transform['prefixes'] ?? [],
                    $transform['except'] ?? []
                ),
                default => throw new RuntimeException('Unsupported style transform: "' . (string)($transform['op'] ?? '') . '"'),
            };
        }

        return $style;
    }

    private static function removeLayersById(array $style, array $ids): array
    {
        if (empty($style['layers']) || !is_array($style['layers'])) {
            return $style;
        }

        $ids = array_flip($ids);
        $style['layers'] = array_values(array_filter(
            $style['layers'],
            static fn ($layer) => !is_array($layer) || !isset($ids[$layer['id'] ?? null])
        ));

        return $style;
    }

    private static function removeLayersByIdContains(array $style, string $contains): array
    {
        if ($contains === '' || empty($style['layers']) || !is_array($style['layers'])) {
            return $style;
        }

        $style['layers'] = array_values(array_filter(
            $style['layers'],
            static fn ($layer) => !is_array($layer)
                || !is_string($layer['id'] ?? null)
                || !str_contains($layer['id'], $contains)
        ));

        return $style;
    }

    private static function removeLayersByIdPrefixExcept(array $style, array $prefixes, array $except): array
    {
        if (empty($prefixes) || empty($style['layers']) || !is_array($style['layers'])) {
            return $style;
        }

        $except = array_flip(array_filter($except, 'is_string'));
        $prefixes = array_values(array_filter($prefixes, 'is_string'));
        $style['layers'] = array_values(array_filter(
            $style['layers'],
            static function ($layer) use ($prefixes, $except): bool {
                if (!is_array($layer) || !is_string($layer['id'] ?? null)) {
                    return true;
                }

                foreach ($prefixes as $prefix) {
                    if (str_starts_with($layer['id'], $prefix)) {
                        return isset($except[$layer['id']]);
                    }
                }

                return true;
            }
        ));

        return $style;
    }

    private static function getTileTemplate(array $cfg, string $styleName, string $sourceName, int $tileIndex): string
    {
        $source = static::getUpstreamSource($cfg, $styleName, $sourceName);

        if (!empty($source['tiles']) && is_array($source['tiles'])) {
            if (!isset(array_values($source['tiles'])[$tileIndex])) {
                throw new UserException('Tile template index is not configured');
            }

            return array_values($source['tiles'])[$tileIndex];
        }

        if (!empty($source['url']) && is_string($source['url'])) {
            $styleCfg = static::getStyleCfg($cfg, $styleName);
            $tileJson = static::decodeJson(static::fetchWithCache($cfg, $styleName, $styleCfg, 'tilejson', $source['url']));
            if (empty($tileJson['tiles']) || !is_array($tileJson['tiles']) || !isset(array_values($tileJson['tiles'])[$tileIndex])) {
                throw new UserException('TileJSON does not define the requested tile template');
            }

            return static::resolveUrl(array_values($tileJson['tiles'])[$tileIndex], $source['url']);
        }

        throw new UserException('Source "' . $sourceName . '" does not define vector tiles');
    }

    private static function getUpstreamSource(array $cfg, string $styleName, string $sourceName): array
    {
        $style = static::loadUpstreamStyle($cfg, $styleName);
        $styleCfg = static::getStyleCfg($cfg, $styleName);
        $styleUrl = static::getUpstreamStyleUrl($styleCfg, $styleName);

        if (empty($style['sources'][$sourceName]) || !is_array($style['sources'][$sourceName])) {
            throw new UserException('Source "' . $sourceName . '" is not configured');
        }

        $source = $style['sources'][$sourceName];
        if (!empty($source['url']) && is_string($source['url'])) {
            $source['url'] = static::resolveUrl($source['url'], $styleUrl);
        }

        if (!empty($source['tiles']) && is_array($source['tiles'])) {
            $source['tiles'] = array_map(
                static fn ($tileUrl) => is_string($tileUrl) ? static::resolveUrl($tileUrl, $styleUrl) : $tileUrl,
                $source['tiles']
            );
        }

        return $source;
    }

    private static function loadUpstreamStyle(array $cfg, string $styleName): array
    {
        $styleCfg = static::getStyleCfg($cfg, $styleName);
        $styleUrl = static::getUpstreamStyleUrl($styleCfg, $styleName);

        return static::decodeJson(static::fetchWithCache($cfg, $styleName, $styleCfg, 'style', $styleUrl));
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
        static::assertAllowedUpstreamUrl($styleCfg, $upstreamUrl);

        $cachePath = static::getCachePath($cfg, $styleName, $resourceType, $upstreamUrl);
        $cacheTtl = static::getCacheTtl($styleCfg, $resourceType);
        $cacheMTime = @filemtime($cachePath);

        if ($cacheMTime !== false && time() - $cacheMTime <= $cacheTtl) {
            $cachedData = @file_get_contents($cachePath);
            if ($cachedData !== false) {
                return $cachedData;
            }
        }

        $data = @file_get_contents($upstreamUrl);
        if ($data === false) {
            if ($cacheMTime !== false) {
                $cachedData = @file_get_contents($cachePath);
                if ($cachedData !== false) {
                    return $cachedData;
                }
            }

            throw new UserException('Could not fetch upstream map asset');
        }

        Utils::writeToFile($cachePath, $data);

        return $data;
    }

    private static function assertAllowedUpstreamUrl(array $styleCfg, string $url): void
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;

        if (!is_string($scheme)) {
            throw new UserException('Upstream URL is not allowed');
        }

        $allowedSchemes = $styleCfg['allowedSchemes'] ?? ['https'];
        if (!in_array($scheme, $allowedSchemes, true)) {
            throw new UserException('Upstream URL is not allowed');
        }

        $path = $parts['path'] ?? '';
        if (!is_string($path)) {
            throw new UserException('Upstream URL is not allowed');
        }

        if ($scheme === 'file') {
            static::assertAllowedPathPrefix($styleCfg, $path);
            return;
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host) || !in_array($host, $styleCfg['allowedHosts'] ?? [], true)) {
            throw new UserException('Upstream URL is not allowed');
        }

        static::assertAllowedPathPrefix($styleCfg, $path);
    }

    private static function assertAllowedPathPrefix(array $styleCfg, string $path): void
    {
        foreach (($styleCfg['allowedPathPrefixes'] ?? []) as $prefix) {
            if (is_string($prefix) && str_starts_with($path, $prefix)) {
                return;
            }
        }

        throw new UserException('Upstream URL is not allowed');
    }

    private static function resolveUrl(string $url, string $baseUrl): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== null) {
            return $url;
        }

        $baseParts = parse_url($baseUrl);
        if (empty($baseParts['scheme']) || !is_string($baseParts['scheme'])) {
            throw new UserException('Upstream URL is not allowed');
        }

        $urlParts = parse_url($url);
        $urlPath = $urlParts['path'] ?? '';
        if (!is_string($urlPath)) {
            throw new UserException('Upstream URL is not allowed');
        }

        $basePath = $baseParts['path'] ?? '/';
        if (!is_string($basePath)) {
            $basePath = '/';
        }

        $path = str_starts_with($urlPath, '/')
            ? $urlPath
            : rtrim(dirname($basePath), '/') . '/' . $urlPath;

        $path = static::normalizePath($path);
        $query = isset($urlParts['query']) ? '?' . $urlParts['query'] : '';

        if ($baseParts['scheme'] === 'file') {
            return 'file://' . $path . $query;
        }

        if (empty($baseParts['host']) || !is_string($baseParts['host'])) {
            throw new UserException('Upstream URL is not allowed');
        }

        $authority = $baseParts['scheme'] . '://' . $baseParts['host'];
        if (!empty($baseParts['port']) && is_int($baseParts['port'])) {
            $authority .= ':' . $baseParts['port'];
        }

        return $authority . $path . $query;
    }

    private static function normalizePath(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return ($isAbsolute ? '/' : '') . implode('/', $segments);
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
