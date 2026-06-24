<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\MapboxStyleProxy;
use PHPUnit\Framework\TestCase;

class BasemapDeIntegrationTest extends TestCase
{
    private string $cacheDir;

    public function testRealBasemapDeStyleAndAssetsCanBeProxied(): void
    {
        if (getenv('RUN_BASEMAPDE_INTEGRATION_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_BASEMAPDE_INTEGRATION_TESTS=1 to run real basemap.de integration tests.');
        }

        $cfg = $this->getConfig();

        $styleResponse = MapboxStyleProxy::handleRequest($cfg, '/map-assets/styles/city-default.json');
        $this->assertSame('application/json', $styleResponse->mimeType);

        $style = json_decode($styleResponse->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/map-assets/tilejson/city-default/smarttiles_de.json', $style['sources']['smarttiles_de']['url']);
        $this->assertStringStartsWith('/map-assets/sprites/city-default/', $style['sprite']);
        $this->assertStringStartsWith('/map-assets/glyphs/city-default/', $style['glyphs']);
        $this->assertStringNotContainsString('sgx.geodatenzentrum.de', $styleResponse->body);
        $this->assertSame(
            'City map attribution, Darstellung verandert',
            $style['metadata']['mapsight:attribution']
        );

        $layerIds = array_column($style['layers'], 'id');
        $this->assertNotContains('Symbol_IwnF_Parkplatz', $layerIds);
        $this->assertNotContains('Gebaeudepunkt_Bildung_Forschung', $layerIds);
        $this->assertSame(
            [],
            array_values(array_filter($layerIds, static fn (string $id): bool => str_contains($id, 'Bahnstrecke')))
        );

        $tileJsonResponse = MapboxStyleProxy::handleRequest($cfg, '/map-assets/tilejson/city-default/smarttiles_de.json');
        $this->assertSame('application/json', $tileJsonResponse->mimeType);
        $tileJson = json_decode($tileJsonResponse->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/map-assets/tiles/city-default/smarttiles_de/0/{z}/{x}/{y}.pbf', $tileJson['tiles'][0]);

        $tileResponse = MapboxStyleProxy::handleRequest($cfg, '/map-assets/tiles/city-default/smarttiles_de/0/0/0/0.pbf');
        $this->assertSame('application/x-protobuf', $tileResponse->mimeType);
        $this->assertGreaterThan(1000, strlen($tileResponse->body));

        $spriteJsonResponse = MapboxStyleProxy::handleRequest($cfg, $style['sprite'] . '.json');
        $this->assertSame('application/json', $spriteJsonResponse->mimeType);
        $this->assertGreaterThan(1000, strlen($spriteJsonResponse->body));

        $spritePngResponse = MapboxStyleProxy::handleRequest($cfg, $style['sprite'] . '.png');
        $this->assertSame('image/png', $spritePngResponse->mimeType);
        $this->assertGreaterThan(1000, strlen($spritePngResponse->body));

        $glyphPath = str_replace(
            ['{fontstack}', '{range}'],
            ['Roboto%20Regular', '0-255'],
            $style['glyphs']
        );
        $glyphResponse = MapboxStyleProxy::handleRequest($cfg, $glyphPath);
        $this->assertSame('application/x-protobuf', $glyphResponse->mimeType);
        $this->assertGreaterThan(1000, strlen($glyphResponse->body));
    }

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/basemapde_integration_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cacheDir);
    }

    private function getConfig(): array
    {
        return [
            'cacheServerPath' => $this->cacheDir,
            'mapAssetBasePath' => '/map-assets',
            'styles' => [
                'city-default' => [
                    'upstreamStyleUrl' => 'https://sgx.geodatenzentrum.de/gdz_basemapde_vektor/styles/bm_web_col.json',
                    'allowedHosts' => ['sgx.geodatenzentrum.de'],
                    'allowedPathPrefixes' => [
                        '/gdz_basemapde_vektor/styles/',
                        '/gdz_basemapde_vektor/tiles/',
                        '/gdz_basemapde_vektor/sprites/',
                        '/gdz_basemapde_vektor/fonts/',
                    ],
                    'cacheTtls' => [
                        'style' => 86400,
                        'tilejson' => 86400,
                        'tile' => 604800,
                        'sprite' => 2592000,
                        'glyph' => 2592000,
                    ],
                    'transforms' => [
                        [
                            'op' => 'removeLayersById',
                            'ids' => [
                                'Symbol_IwnF_Parkplatz',
                                'Symbol_VerkehrF_Parkplatz',
                                'Gebaeudepunkt_Parkhaus',
                                'Gebaeudepunkt_Bildung_Forschung',
                            ],
                        ],
                        ['op' => 'removeLayersByIdContains', 'contains' => 'Bahnstrecke'],
                    ],
                    'attribution' => 'City map attribution, Darstellung verandert',
                ],
            ],
        ];
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
