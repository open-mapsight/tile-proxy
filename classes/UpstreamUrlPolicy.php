<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

class UpstreamUrlPolicy
{
    /**
     * @param array<string, mixed> $styleCfg
     */
    public static function assertAllowedUpstreamUrl(array $styleCfg, string $url): void
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

    /**
     * @param array<string, mixed> $styleCfg
     */
    public static function assertAllowedPathPrefix(array $styleCfg, string $path): void
    {
        foreach (($styleCfg['allowedPathPrefixes'] ?? []) as $prefix) {
            if (is_string($prefix) && str_starts_with($path, $prefix)) {
                return;
            }
        }

        throw new UserException('Upstream URL is not allowed');
    }

    public static function resolveUrl(string $url, string $baseUrl): string
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

    public static function normalizePath(string $path): string
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
}
