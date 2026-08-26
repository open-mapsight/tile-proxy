<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

class XyzTile
{
    public const EARTH_RADIUS = 6378137.0;

    public const ORIGIN_SHIFT = 20037508.342789244;

    public const MAX_ZOOM = 30;

    /**
     * Web Mercator tile bounds in EPSG:3857 meters: minx, miny, maxx, maxy.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function bbox3857(int $z, int $x, int $y): array
    {
        self::assertValid($z, $x, $y);

        $n = 2 ** $z;
        $tileSize = (2.0 * self::ORIGIN_SHIFT) / $n;

        $minX = ($x * $tileSize) - self::ORIGIN_SHIFT;
        $maxX = (($x + 1) * $tileSize) - self::ORIGIN_SHIFT;
        $maxY = self::ORIGIN_SHIFT - ($y * $tileSize);
        $minY = self::ORIGIN_SHIFT - (($y + 1) * $tileSize);

        return [$minX, $minY, $maxX, $maxY];
    }

    /**
     * Geographic bounds of a Web Mercator XYZ tile: minLon, minLat, maxLon, maxLat.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function bbox4326(int $z, int $x, int $y): array
    {
        [$minX, $minY, $maxX, $maxY] = self::bbox3857($z, $x, $y);
        [$minLon, $minLat] = self::metersToLonLat($minX, $minY);
        [$maxLon, $maxLat] = self::metersToLonLat($maxX, $maxY);

        return [$minLon, $minLat, $maxLon, $maxLat];
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     */
    public static function formatBbox(array $bbox): string
    {
        return implode(',', array_map(static fn (float $value): string => self::formatCoord($value), $bbox));
    }

    /**
     * @return array{0: float, 1: float}
     */
    public static function metersToLonLat(float $x, float $y): array
    {
        $lon = ($x / self::ORIGIN_SHIFT) * 180.0;
        $lat = ($y / self::ORIGIN_SHIFT) * 180.0;
        $lat = (180.0 / M_PI) * ((2.0 * atan(exp($lat * M_PI / 180.0))) - (M_PI / 2.0));

        return [$lon, $lat];
    }

    public static function assertValid(int $z, int $x, int $y): void
    {
        if ($z < 0 || $z > self::MAX_ZOOM) {
            throw new UserException('Tile zoom "' . $z . '" is out of range');
        }

        $maxIndex = (2 ** $z) - 1;
        if ($x < 0 || $x > $maxIndex || $y < 0 || $y > $maxIndex) {
            throw new UserException('Tile "' . $z . '/' . $x . '/' . $y . '" is out of range');
        }
    }

    private static function formatCoord(float $value): string
    {
        $formatted = sprintf('%.12F', $value);
        $formatted = rtrim($formatted, '0');

        return rtrim($formatted, '.') ?: '0';
    }
}
