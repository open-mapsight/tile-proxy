<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\WmsUrl;
use OpenMapsight\TileProxy\XyzTile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WmsUrlTest extends TestCase
{
    /** @return array<string, string|null> */
    private function equatorTile(): array
    {
        return ['z' => '18', 'x' => '131072', 'y' => '131072', 'prefix' => null];
    }

    public function testBuildsWms11GetMapInWebMercator(): void
    {
        $url = WmsUrl::fromConfig([
            'url' => 'https://geoportal.example.de/geoserver/luftbilder/wms',
            'layers' => 'Luftbild_2024',
            'format' => 'image/jpeg',
        ], $this->equatorTile());

        $this->assertStringStartsWith(
            'https://geoportal.example.de/geoserver/luftbilder/wms?',
            $url
        );

        $query = [];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('WMS', $query['SERVICE']);
        $this->assertSame('GetMap', $query['REQUEST']);
        $this->assertSame('1.1.1', $query['VERSION']);
        $this->assertSame('Luftbild_2024', $query['LAYERS']);
        $this->assertSame('image/jpeg', $query['FORMAT']);
        $this->assertSame('EPSG:3857', $query['SRS']);
        $this->assertArrayNotHasKey('CRS', $query);
        $this->assertSame('256', $query['WIDTH']);
        $this->assertSame('256', $query['HEIGHT']);
        $this->assertSame('false', $query['TRANSPARENT']);
        $this->assertSame(
            XyzTile::formatBbox(XyzTile::bbox3857(18, 131072, 131072)),
            $query['BBOX']
        );
    }

    public function testWms13UsesCrsAndLatLonAxisOrderForEpsg4326(): void
    {
        $url = WmsUrl::fromConfig([
            'url' => 'https://example.de/wms',
            'layers' => 'ortho',
            'version' => '1.3.0',
            'srs' => 'EPSG:4326',
        ], $this->equatorTile());

        $query = [];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        [$minLon, $minLat, $maxLon, $maxLat] = XyzTile::bbox4326(18, 131072, 131072);

        $this->assertSame('EPSG:4326', $query['CRS']);
        $this->assertArrayNotHasKey('SRS', $query);
        $this->assertSame(
            XyzTile::formatBbox([$minLat, $minLon, $maxLat, $maxLon]),
            $query['BBOX']
        );
    }

    public function testWms11KeepsLonLatAxisOrderForEpsg4326(): void
    {
        $url = WmsUrl::fromConfig([
            'url' => 'https://example.de/wms',
            'layers' => 'ortho',
            'srs' => 'EPSG:4326',
        ], $this->equatorTile());

        $query = [];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('EPSG:4326', $query['SRS']);
        $this->assertSame(
            XyzTile::formatBbox(XyzTile::bbox4326(18, 131072, 131072)),
            $query['BBOX']
        );
    }

    public function testAppendsToExistingQueryString(): void
    {
        $url = WmsUrl::fromConfig([
            'url' => 'https://example.de/wms?map=luftbilder',
            'layers' => 'ortho',
        ], $this->equatorTile());

        $this->assertStringStartsWith('https://example.de/wms?map=luftbilder&', $url);
    }

    public function testExtraParamsAreMerged(): void
    {
        $url = WmsUrl::fromConfig([
            'url' => 'https://example.de/wms',
            'layers' => 'ortho',
            'extraParams' => [
                'BGCOLOR' => '0x000000',
            ],
        ], $this->equatorTile());

        $query = [];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('0x000000', $query['BGCOLOR']);
    }

    public function testExpandPlaceholdersFillsXyzAndMercatorBbox(): void
    {
        $url = WmsUrl::expandPlaceholders(
            'https://example.de/wms?BBOX={bbox}&Z={z}&X={x}&Y={y}',
            $this->equatorTile()
        );

        $this->assertSame(
            'https://example.de/wms?BBOX=' . XyzTile::formatBbox(XyzTile::bbox3857(18, 131072, 131072))
            . '&Z=18&X=131072&Y=131072',
            $url
        );
    }

    public function testExpandPlaceholdersSupportsGeographicBbox(): void
    {
        $url = WmsUrl::expandPlaceholders(
            'https://example.de/wms?BBOX={bbox4326}',
            $this->equatorTile()
        );

        $this->assertSame(
            'https://example.de/wms?BBOX=' . XyzTile::formatBbox(XyzTile::bbox4326(18, 131072, 131072)),
            $url
        );
    }

    public function testExpandPlaceholdersKeepsPrefix(): void
    {
        $url = WmsUrl::expandPlaceholders(
            'https://example.de/{prefix}/{z}/{x}/{y}.png',
            ['z' => '1', 'x' => '0', 'y' => '0', 'prefix' => 'muted']
        );

        $this->assertSame('https://example.de/muted/1/0/0.png', $url);
    }

    public function testRejectsUnsupportedSrs(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WMS srs "EPSG:25832" is not supported');

        WmsUrl::fromConfig([
            'url' => 'https://example.de/wms',
            'layers' => 'ortho',
            'srs' => 'EPSG:25832',
        ], $this->equatorTile());
    }

    public function testRequiresLayers(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WMS source requires `layers`');

        WmsUrl::fromConfig([
            'url' => 'https://example.de/wms',
        ], $this->equatorTile());
    }
}
