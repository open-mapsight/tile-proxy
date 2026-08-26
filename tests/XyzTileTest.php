<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\UserException;
use OpenMapsight\TileProxy\XyzTile;
use PHPUnit\Framework\TestCase;

class XyzTileTest extends TestCase
{
    public function testWorldTileHasPublishedMercatorExtent(): void
    {
        [$minX, $minY, $maxX, $maxY] = XyzTile::bbox3857(0, 0, 0);

        $this->assertEqualsWithDelta(-20037508.342789244, $minX, 1e-6);
        $this->assertEqualsWithDelta(-20037508.342789244, $minY, 1e-6);
        $this->assertEqualsWithDelta(20037508.342789244, $maxX, 1e-6);
        $this->assertEqualsWithDelta(20037508.342789244, $maxY, 1e-6);
    }

    public function testWorldTileHasPublishedGeographicExtent(): void
    {
        [$minLon, $minLat, $maxLon, $maxLat] = XyzTile::bbox4326(0, 0, 0);

        $this->assertEqualsWithDelta(-180.0, $minLon, 1e-9);
        $this->assertEqualsWithDelta(-85.0511287798, $minLat, 1e-8);
        $this->assertEqualsWithDelta(180.0, $maxLon, 1e-9);
        $this->assertEqualsWithDelta(85.0511287798, $maxLat, 1e-8);
    }

    public function testNortheastZoom1TileTouchesOrigin(): void
    {
        [$minX, $minY, $maxX, $maxY] = XyzTile::bbox3857(1, 1, 0);

        $this->assertEqualsWithDelta(0.0, $minX, 1e-6);
        $this->assertEqualsWithDelta(0.0, $minY, 1e-6);
        $this->assertEqualsWithDelta(20037508.342789244, $maxX, 1e-6);
        $this->assertEqualsWithDelta(20037508.342789244, $maxY, 1e-6);
    }

    public function testEquatorTileAtZoom18HasExactHalfWorldX(): void
    {
        [$minX, $minY, $maxX, $maxY] = XyzTile::bbox3857(18, 131072, 131072);

        $tileWidth = 20037508.342789244 / 131072;

        $this->assertEqualsWithDelta(0.0, $minX, 1e-6);
        $this->assertEqualsWithDelta(-$tileWidth, $minY, 1e-6);
        $this->assertEqualsWithDelta($tileWidth, $maxX, 1e-6);
        $this->assertEqualsWithDelta(0.0, $maxY, 1e-6);
    }

    public function testEquatorTileLongitudeSpanIs360OverTwoToTheZoom(): void
    {
        [$minLon, $minLat, $maxLon, $maxLat] = XyzTile::bbox4326(18, 131072, 131072);

        $this->assertEqualsWithDelta(0.0, $minLon, 1e-9);
        $this->assertEqualsWithDelta(360.0 / 262144, $maxLon, 1e-9);
        $this->assertLessThan(0.0, $minLat);
        $this->assertEqualsWithDelta(0.0, $maxLat, 1e-9);
    }

    public function testFormatBboxOmitsTrailingZeros(): void
    {
        $this->assertSame('10.5,0,-3,1', XyzTile::formatBbox([10.5, 0.0, -3.0, 1.0]));
    }

    public function testRejectsOutOfRangeZoom(): void
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Tile zoom "31" is out of range');

        XyzTile::bbox3857(31, 0, 0);
    }

    public function testRejectsOutOfRangeTileIndex(): void
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Tile "2/4/0" is out of range');

        XyzTile::bbox3857(2, 4, 0);
    }
}
