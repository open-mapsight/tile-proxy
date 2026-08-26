<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use RuntimeException;

class WmsUrl
{
    /**
     * @param array<string, mixed>         $wms
     * @param array<string, string|null>   $reqArgs
     */
    public static function fromConfig(array $wms, array $reqArgs): string
    {
        $baseUrl = $wms['url'] ?? null;
        if (!is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException('WMS source requires `url`');
        }

        $layers = $wms['layers'] ?? null;
        if (!is_string($layers) || $layers === '') {
            throw new RuntimeException('WMS source requires `layers`');
        }

        $version = isset($wms['version']) ? (string)$wms['version'] : '1.1.1';
        if (!in_array($version, ['1.1.1', '1.3.0'], true)) {
            throw new RuntimeException('Unsupported WMS version "' . $version . '"');
        }

        $srs = strtoupper((string)($wms['srs'] ?? $wms['crs'] ?? 'EPSG:3857'));
        $format = (string)($wms['format'] ?? 'image/png');
        $styles = (string)($wms['styles'] ?? '');
        $width = (int)($wms['width'] ?? 256);
        $height = (int)($wms['height'] ?? 256);
        $transparent = (bool)($wms['transparent'] ?? false);

        $params = [
            'SERVICE' => 'WMS',
            'REQUEST' => 'GetMap',
            'VERSION' => $version,
            'LAYERS' => $layers,
            'STYLES' => $styles,
            'FORMAT' => $format,
            'TRANSPARENT' => $transparent ? 'true' : 'false',
            'WIDTH' => $width,
            'HEIGHT' => $height,
        ];

        if ($version === '1.3.0') {
            $params['CRS'] = $srs;
        } else {
            $params['SRS'] = $srs;
        }

        $params['BBOX'] = self::bboxForRequest(
            (int)$reqArgs['z'],
            (int)$reqArgs['x'],
            (int)$reqArgs['y'],
            $srs,
            $version
        );

        if (isset($wms['extraParams']) && is_array($wms['extraParams'])) {
            foreach ($wms['extraParams'] as $key => $value) {
                if (!is_string($key) || $key === '') {
                    throw new RuntimeException('WMS extraParams keys must be non-empty strings');
                }
                $params[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
            }
        }

        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, string|null> $reqArgs
     */
    public static function expandPlaceholders(string $url, array $reqArgs): string
    {
        if ($reqArgs['prefix'] !== null) {
            $url = str_replace('{prefix}', $reqArgs['prefix'], $url);
        }

        $url = str_replace('{z}', (string)$reqArgs['z'], $url);
        $url = str_replace('{x}', (string)$reqArgs['x'], $url);
        $url = str_replace('{y}', (string)$reqArgs['y'], $url);

        if (!str_contains($url, '{bbox')) {
            return $url;
        }

        $z = (int)$reqArgs['z'];
        $x = (int)$reqArgs['x'];
        $y = (int)$reqArgs['y'];

        $bbox3857 = XyzTile::formatBbox(XyzTile::bbox3857($z, $x, $y));
        $bbox4326 = XyzTile::formatBbox(XyzTile::bbox4326($z, $x, $y));

        $url = str_replace('{bbox3857}', $bbox3857, $url);
        $url = str_replace('{bbox4326}', $bbox4326, $url);
        $url = str_replace('{bbox}', $bbox3857, $url);

        return $url;
    }

    private static function bboxForRequest(int $z, int $x, int $y, string $srs, string $version): string
    {
        if ($srs === 'EPSG:3857') {
            return XyzTile::formatBbox(XyzTile::bbox3857($z, $x, $y));
        }

        if ($srs === 'EPSG:4326' || $srs === 'CRS:84') {
            [$minLon, $minLat, $maxLon, $maxLat] = XyzTile::bbox4326($z, $x, $y);

            if ($version === '1.3.0' && $srs === 'EPSG:4326') {
                return XyzTile::formatBbox([$minLat, $minLon, $maxLat, $maxLon]);
            }

            return XyzTile::formatBbox([$minLon, $minLat, $maxLon, $maxLat]);
        }

        throw new RuntimeException('WMS srs "' . $srs . '" is not supported; use EPSG:3857, EPSG:4326, or CRS:84');
    }
}
