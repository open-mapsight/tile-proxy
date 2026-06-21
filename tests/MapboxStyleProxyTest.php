<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\MapboxStyleProxy;
use OpenMapsight\TileProxy\UserException;
use PHPUnit\Framework\TestCase;

class MapboxStyleProxyTest extends TestCase
{
    private string $tempDir;
    private string $assetDir;
    private string $cacheDir;
    private array $cfg;

    public function testStyleResponseRewritesUrlsAndAppliesTransforms(): void
    {
        $response = MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/styles/city-default.json');

        $this->assertSame('application/json', $response->mimeType);

        $style = json_decode($response->data, true);
        $this->assertSame('/map-assets/tilejson/city-default/fromTileJson.json', $style['sources']['fromTileJson']['url']);
        $this->assertSame(['/map-assets/tiles/city-default/directTiles/0/{z}/{x}/{y}.pbf'], $style['sources']['directTiles']['tiles']);
        $this->assertStringStartsWith('/map-assets/sprites/city-default/', $style['sprite']);
        $this->assertStringStartsWith('/map-assets/glyphs/city-default/', $style['glyphs']);
        $this->assertStringEndsWith('/{fontstack}/{range}.pbf', $style['glyphs']);
        $this->assertSame(
            'City map attribution, Darstellung verandert',
            $style['metadata']['mapsight:attribution']
        );

        $this->assertSame(['keep-layer', 'Symbol_Keep'], array_column($style['layers'], 'id'));
    }

    public function testTileJsonResponseRewritesTileTemplates(): void
    {
        $response = MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/tilejson/city-default/fromTileJson.json');

        $this->assertSame('application/json', $response->mimeType);

        $tileJson = json_decode($response->data, true);
        $this->assertSame(['/map-assets/tiles/city-default/fromTileJson/0/{z}/{x}/{y}.pbf'], $tileJson['tiles']);
    }

    public function testVectorTileResponseIsProxiedAndCached(): void
    {
        $path = '/map-assets/tiles/city-default/directTiles/0/12/2200/1340.pbf';

        $response = MapboxStyleProxy::handleRequest($this->cfg, $path);
        $this->assertSame('application/x-protobuf', $response->mimeType);
        $this->assertSame('vector tile bytes', $response->data);

        unlink($this->assetDir . '/tiles/12/2200/1340.pbf');

        $cachedResponse = MapboxStyleProxy::handleRequest($this->cfg, $path);
        $this->assertSame('vector tile bytes', $cachedResponse->data);
    }

    public function testSpriteAndGlyphResponsesAreProxied(): void
    {
        $style = json_decode(
            MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/styles/city-default.json')->data,
            true
        );

        $spriteJsonPath = $style['sprite'] . '.json';
        $spriteJsonResponse = MapboxStyleProxy::handleRequest($this->cfg, $spriteJsonPath);
        $this->assertSame('application/json', $spriteJsonResponse->mimeType);
        $this->assertSame('{"sprite":true}', $spriteJsonResponse->data);

        $spritePngResponse = MapboxStyleProxy::handleRequest($this->cfg, $style['sprite'] . '.png');
        $this->assertSame('image/png', $spritePngResponse->mimeType);
        $this->assertSame('png bytes', $spritePngResponse->data);

        $glyphPath = str_replace(
            ['{fontstack}', '{range}'],
            ['Open%20Sans%20Regular', '0-255'],
            $style['glyphs']
        );
        $glyphResponse = MapboxStyleProxy::handleRequest($this->cfg, $glyphPath);
        $this->assertSame('application/x-protobuf', $glyphResponse->mimeType);
        $this->assertSame('glyph bytes', $glyphResponse->data);
    }

    public function testDisallowedUpstreamUrlsAreRejected(): void
    {
        $stylePath = $this->assetDir . '/blocked-style.json';
        file_put_contents($stylePath, json_encode([
            'version' => 8,
            'sources' => [
                'blocked' => [
                    'type' => 'vector',
                    'url' => 'https://evil.example.test/tilejson.json',
                ],
            ],
            'layers' => [],
        ], JSON_THROW_ON_ERROR));

        $cfg = $this->cfg;
        $cfg['styles']['blocked'] = $cfg['styles']['city-default'];
        $cfg['styles']['blocked']['upstreamStyleUrl'] = 'file://' . $stylePath;

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Upstream URL is not allowed');

        MapboxStyleProxy::handleRequest($cfg, '/map-assets/styles/blocked.json');
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mapbox_style_proxy_test_' . uniqid();
        $this->assetDir = $this->tempDir . '/assets';
        $this->cacheDir = $this->tempDir . '/cache';

        mkdir($this->assetDir . '/styles', 0777, true);
        mkdir($this->assetDir . '/tilejson', 0777, true);
        mkdir($this->assetDir . '/tiles/12/2200', 0777, true);
        mkdir($this->assetDir . '/sprites', 0777, true);
        mkdir($this->assetDir . '/fonts/Open Sans Regular', 0777, true);

        file_put_contents($this->assetDir . '/tiles/12/2200/1340.pbf', 'vector tile bytes');
        file_put_contents($this->assetDir . '/sprites/city_sprite.json', '{"sprite":true}');
        file_put_contents($this->assetDir . '/sprites/city_sprite.png', 'png bytes');
        file_put_contents($this->assetDir . '/fonts/Open Sans Regular/0-255.pbf', 'glyph bytes');
        file_put_contents($this->assetDir . '/tilejson/source.json', json_encode([
            'tilejson' => '3.0.0',
            'tiles' => ['../tiles/{z}/{x}/{y}.pbf'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->assetDir . '/styles/city.json', json_encode([
            'version' => 8,
            'name' => 'City map',
            'sprite' => '../sprites/city_sprite',
            'glyphs' => '../fonts/{fontstack}/{range}.pbf',
            'sources' => [
                'fromTileJson' => [
                    'type' => 'vector',
                    'url' => '../tilejson/source.json',
                ],
                'directTiles' => [
                    'type' => 'vector',
                    'tiles' => ['../tiles/{z}/{x}/{y}.pbf'],
                ],
            ],
            'layers' => [
                ['id' => 'keep-layer', 'type' => 'background'],
                ['id' => 'remove-by-id', 'type' => 'symbol'],
                ['id' => 'RailTrack_Main', 'type' => 'line'],
                ['id' => 'Symbol_Remove', 'type' => 'symbol'],
                ['id' => 'Gebaeudepunkt_Remove', 'type' => 'symbol'],
                ['id' => 'Symbol_Keep', 'type' => 'symbol'],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->cfg = [
            'cacheServerPath' => $this->cacheDir,
            'publicBasePath' => '/map-assets',
            'styles' => [
                'city-default' => [
                    'upstreamStyleUrl' => 'file://' . $this->assetDir . '/styles/city.json',
                    'allowedSchemes' => ['file', 'https'],
                    'allowedHosts' => ['tiles.example.test'],
                    'allowedPathPrefixes' => [
                        $this->assetDir . '/styles/',
                        $this->assetDir . '/tilejson/',
                        $this->assetDir . '/tiles/',
                        $this->assetDir . '/sprites/',
                        $this->assetDir . '/fonts/',
                        '/vector/',
                    ],
                    'cacheTtls' => [
                        'style' => 86400,
                        'tilejson' => 86400,
                        'tile' => 604800,
                        'sprite' => 2592000,
                        'glyph' => 2592000,
                    ],
                    'transforms' => [
                        ['op' => 'removeLayersById', 'ids' => ['remove-by-id']],
                        ['op' => 'removeLayersByIdContains', 'contains' => 'RailTrack'],
                        [
                            'op' => 'removeLayersByIdPrefixExcept',
                            'prefixes' => ['Symbol_', 'Gebaeudepunkt_'],
                            'except' => ['Symbol_Keep'],
                        ],
                    ],
                    'attribution' => 'City map attribution, Darstellung verandert',
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
