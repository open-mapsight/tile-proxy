<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use RuntimeException;

class Proxy
{
    public static function runFromJsonConfigFile(string $cfgPath): void
    {
        $cfg = file_get_contents($cfgPath);
        if ($cfg === false) {
            throw new RuntimeException('Could not read config file "' . $cfgPath . '"');
        }

        self::run(Utils::parseJsoncString($cfg));
    }

    /**
     * @param array<string, mixed> $cfg
     */
    public static function run(array $cfg): void
    {
        $requestPath = $_SERVER['REQUEST_URI'] ?? '/';

        if (self::isMapboxAssetRequest($cfg, $requestPath)) {
            MapboxStyleProxy::run($cfg, $requestPath);
            return;
        }

        if (!empty($cfg['ops'])) {
            Base::run($cfg);
            return;
        }

        if (!empty($cfg['styles'])) {
            MapboxStyleProxy::run($cfg, $requestPath);
            return;
        }

        HttpResponse::sendRequest(
            $cfg,
            static fn () => throw new UserException('No proxy configuration found')
        );
    }

    /**
     * @param array<string, mixed> $cfg
     */
    public static function isMapboxAssetRequest(array $cfg, string $requestPath): bool
    {
        if (empty($cfg['styles']) || !is_array($cfg['styles'])) {
            return false;
        }

        $routePath = self::mapboxRoutePath($cfg, $requestPath);
        if ($routePath === null) {
            return false;
        }

        return preg_match('#^(styles|tilejson|tiles|sprites|glyphs)/#', $routePath) === 1;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private static function mapboxRoutePath(array $cfg, string $requestPath): ?string
    {
        $basePath = Utils::mapAssetBasePath($cfg);
        $path = parse_url($requestPath, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        if ($basePath !== '') {
            if ($path !== $basePath && !str_starts_with($path, $basePath . '/')) {
                return null;
            }

            return ltrim(substr($path, strlen($basePath)), '/');
        }

        return ltrim($path, '/');
    }
}
