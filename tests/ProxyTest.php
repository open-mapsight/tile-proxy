<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\Proxy;
use PHPUnit\Framework\TestCase;

class ProxyTest extends TestCase
{
    public function testDetectsMapboxAssetRequests(): void
    {
        $cfg = [
            'mapAssetBasePath' => '/map-assets',
            'styles' => ['city-default' => []],
            'ops' => [['cacheServerName' => 'base']],
        ];

        $this->assertTrue(Proxy::isMapboxAssetRequest($cfg, '/map-assets/styles/city-default.json'));
        $this->assertTrue(Proxy::isMapboxAssetRequest($cfg, '/map-assets/fonts/city-default/ubuntu/400.css'));
        $this->assertTrue(Proxy::isMapboxAssetRequest($cfg, '/map-assets/fonts/city-default/ubuntu/latin-400-normal.woff2'));
        $this->assertTrue(Proxy::isMapboxAssetRequest($cfg, '/map-assets/tiles/city-default/source/0/0/0/0.pbf'));
        $this->assertFalse(Proxy::isMapboxAssetRequest($cfg, '/tiles?x=0&y=0&z=0'));
        $this->assertFalse(Proxy::isMapboxAssetRequest($cfg, '/other/styles/city-default.json'));
    }

    public function testPublicBasePathAliasStillWorks(): void
    {
        $cfg = [
            'publicBasePath' => '/map-assets',
            'styles' => ['city-default' => []],
        ];

        $this->assertTrue(Proxy::isMapboxAssetRequest($cfg, '/map-assets/styles/city-default.json'));
    }

    public function testDetectsMapboxRoutesWithoutMapAssetBasePath(): void
    {
        $cfg = [
            'styles' => ['city-default' => []],
        ];

        $this->assertTrue(Proxy::isMapboxAssetRequest($cfg, '/styles/city-default.json'));
        $this->assertFalse(Proxy::isMapboxAssetRequest($cfg, '/health'));
    }
}
