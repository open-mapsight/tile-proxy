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

        $style = json_decode($response->body, true);
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

        $tileJson = json_decode($response->body, true);
        $this->assertSame(['/map-assets/tiles/city-default/fromTileJson/0/{z}/{x}/{y}.pbf'], $tileJson['tiles']);
        $this->assertSame('Source-specific attribution', $tileJson['attribution']);
    }

    public function testFontMetadataOverridesUpstreamAndUnknownFontsStayLocal(): void
    {
        $path = $this->assetDir . '/styles/city.json';
        $upstream = json_decode(file_get_contents($path), true);
        $upstream['metadata']['ol:webfonts'] = 'https://external.example/{font-family}.css';
        file_put_contents($path, json_encode($upstream));
        $style = json_decode(MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/styles/city-default.json')->body, true);
        $this->assertSame('/map-assets/fonts/city-default/{font-family}/{fontweight}{-fontstyle}.css', $style['metadata']['ol:webfonts']);
        $response = MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/fonts/city-default/unknown-font/400.css');
        $this->assertSame('text/css', $response->mimeType);
        $this->assertSame('', $response->body);
    }

    public function testFontCssAndBytesUsePinnedCachedAssetsAndSelectedSubsets(): void
    {
        $this->cfg['styles']['city-default']['webfonts'] = ['ubuntu' => [
            'version' => '5.2.8', 'faces' => ['600' => '500'], 'subsets' => ['latin', 'latin-ext'],
        ]];
        $this->cfg['styles']['city-default']['allowedHosts'][] = 'cdn.jsdelivr.net';
        $this->cfg['styles']['city-default']['allowedPathPrefixes'][] = '/npm/@fontsource/ubuntu@5.2.8/';
        $css = '@import "https://external.example/unwanted.css";';
        foreach (['latin', 'latin-ext', 'greek'] as $subset) {
            $css .= "@font-face {font-family: 'Ubuntu'; font-style: normal; font-weight: 500; src: url(./files/ubuntu-$subset-500-normal.woff2) format('woff2'), url(./files/ubuntu-$subset-500-normal.woff) format('woff'); unicode-range: U+0000-00FF;}";
        }
        foreach (['font-css' => ['500.css', $css], 'font' => ['files/ubuntu-latin-500-normal.woff2', 'font bytes']] as $type => [$file, $body]) {
            $dir = $this->cacheDir . '/mapbox-style-proxy/city-default/' . $type;
            mkdir($dir, 0777, true);
            file_put_contents($dir . '/' . sha1('https://cdn.jsdelivr.net/npm/@fontsource/ubuntu@5.2.8/' . $file), $body);
        }
        $response = MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/fonts/city-default/ubuntu/600.css');
        $this->assertSame(2, substr_count($response->body, '@font-face'));
        $this->assertSame(2, substr_count($response->body, 'font-weight: 600;'));
        $this->assertStringContainsString('unicode-range: U+0000-00FF;', $response->body);
        $this->assertStringContainsString('/map-assets/fonts/city-default/ubuntu/latin-ext-600-normal.woff2', $response->body);
        $this->assertStringNotContainsString('https:', $response->body);
        $this->assertStringNotContainsString('greek', $response->body);
        $this->assertStringNotContainsString("format('woff')", $response->body);
        $unconfigured = MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/fonts/city-default/ubuntu/500.css');
        $this->assertSame('', $unconfigured->body);
        $font = MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/fonts/city-default/ubuntu/latin-600-normal.woff2');
        $this->assertSame('font/woff2', $font->mimeType);
        $this->assertSame('font bytes', $font->body);

        $this->expectException(UserException::class);
        MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/fonts/city-default/ubuntu/greek-600-normal.woff2');
    }

    public function testFontFetchingStillEnforcesUpstreamAllowList(): void
    {
        $this->cfg['styles']['city-default']['webfonts'] = ['ubuntu' => ['version' => '5.2.8', 'faces' => ['400']]];
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Upstream URL is not allowed');
        MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/fonts/city-default/ubuntu/400.css');
    }

    public function testVectorTileResponseIsProxiedAndCached(): void
    {
        $path = '/map-assets/tiles/city-default/directTiles/0/12/2200/1340.pbf';

        $response = MapboxStyleProxy::handleRequest($this->cfg, $path);
        $this->assertSame('application/x-protobuf', $response->mimeType);
        $this->assertSame('vector tile bytes', $response->body);

        unlink($this->assetDir . '/tiles/12/2200/1340.pbf');

        $cachedResponse = MapboxStyleProxy::handleRequest($this->cfg, $path);
        $this->assertSame('vector tile bytes', $cachedResponse->body);
    }

    public function testTileBoundsRejectsRemoteTilesBeforeAnyUpstreamFetch(): void
    {
        $this->cfg['styles']['city-default']['tileBounds'] = [10.50, 52.25, 10.55, 52.28];
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('outside the configured tileBounds');
        MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/tiles/city-default/directTiles/0/12/2200/1340.pbf');
    }

    public function testTileJsonAdvertisesTheRestrictedExtent(): void
    {
        $this->cfg['styles']['city-default']['tileBounds'] = [10.50, 52.25, 10.55, 52.28];
        $response = MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/tilejson/city-default/fromTileJson.json');
        $this->assertSame([10.50, 52.25, 10.55, 52.28], json_decode($response->body, true)['bounds']);
    }

    public function testStyleAdvertisesTileBoundsOnInlineTileSources(): void
    {
        $this->cfg['styles']['city-default']['tileBounds'] = [10.50, 52.25, 10.55, 52.28];
        $style = json_decode(MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/styles/city-default.json')->body, true);
        $this->assertSame([10.50, 52.25, 10.55, 52.28], $style['sources']['directTiles']['bounds']);
        $this->assertArrayNotHasKey('bounds', $style['sources']['fromTileJson']);
    }

    public function testMalformedTileBoundsAreAConfigurationError(): void
    {
        $this->cfg['styles']['city-default']['tileBounds'] = [10.55, 52.25, 10.50, 52.28];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid tileBounds');
        MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/tiles/city-default/directTiles/0/12/2200/1340.pbf');
    }

    public function testInvalidTileCoordinatesAreRejected(): void
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('out of range');
        MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/tiles/city-default/directTiles/0/31/0/0.pbf');
    }

    public function testTileBoundsKeepsAnIntersectingCachedTile(): void
    {
        $bounds = \OpenMapsight\TileProxy\XyzTile::bbox4326(12, 2200, 1340);
        $this->cfg['styles']['city-default']['tileBounds'] = [$bounds[0]+0.001, $bounds[1]+0.001, $bounds[2]-0.001, $bounds[3]-0.001];
        $response = MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/tiles/city-default/directTiles/0/12/2200/1340.pbf');
        $this->assertSame('vector tile bytes', $response->body);
    }

    public function testSpriteAndGlyphResponsesAreProxied(): void
    {
        $style = json_decode(
            MapboxStyleProxy::handleRequest($this->cfg, '/map-assets/styles/city-default.json')->body,
            true
        );

        $spriteJsonPath = $style['sprite'] . '.json';
        $spriteJsonResponse = MapboxStyleProxy::handleRequest($this->cfg, $spriteJsonPath);
        $this->assertSame('application/json', $spriteJsonResponse->mimeType);
        $this->assertSame('{"sprite":true}', $spriteJsonResponse->body);

        $spritePngResponse = MapboxStyleProxy::handleRequest($this->cfg, $style['sprite'] . '.png');
        $this->assertSame('image/png', $spritePngResponse->mimeType);
        $this->assertSame('png bytes', $spritePngResponse->body);

        $glyphPath = str_replace(
            ['{fontstack}', '{range}'],
            ['Open%20Sans%20Regular', '0-255'],
            $style['glyphs']
        );
        $glyphResponse = MapboxStyleProxy::handleRequest($this->cfg, $glyphPath);
        $this->assertSame('application/x-protobuf', $glyphResponse->mimeType);
        $this->assertSame('glyph bytes', $glyphResponse->body);
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
                    'attribution' => 'Source-specific attribution',
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
            'mapAssetBasePath' => '/map-assets',
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
