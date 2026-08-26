<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\Metadata;
use OpenMapsight\TileProxy\MetadataScope;
use OpenMapsight\TileProxy\Ops\SrcOp;
use OpenMapsight\TileProxy\Processor;
use OpenMapsight\TileProxy\Result;
use OpenMapsight\TileProxy\WmsUrl;
use OpenMapsight\TileProxy\XyzTile;
use PHPUnit\Framework\TestCase;

class SrcOpTest extends TestCase
{
    private string $tempDir;

    public function testWmsConfigBuildsGetMapUrl(): void
    {
        $url = TestableSrcOp::urlForTest(
            [
                'wms' => [
                    'url' => 'https://geoportal.example.de/geoserver/luftbilder/wms',
                    'layers' => 'Luftbild_2024',
                ],
                'mimeType' => 'image/jpeg',
            ],
            ['z' => '18', 'x' => '131072', 'y' => '131072', 'prefix' => null]
        );

        $this->assertSame(
            WmsUrl::fromConfig([
                'url' => 'https://geoportal.example.de/geoserver/luftbilder/wms',
                'layers' => 'Luftbild_2024',
                'format' => 'image/jpeg',
            ], ['z' => '18', 'x' => '131072', 'y' => '131072', 'prefix' => null]),
            $url
        );
    }

    public function testUrlTemplateExpandsMercatorBbox(): void
    {
        $url = TestableSrcOp::urlForTest(
            [
                'urls' => ['https://example.de/wms?BBOX={bbox3857}&Z={z}'],
            ],
            ['z' => '18', 'x' => '131072', 'y' => '131072', 'prefix' => null]
        );

        $this->assertSame(
            'https://example.de/wms?BBOX=' . XyzTile::formatBbox(XyzTile::bbox3857(18, 131072, 131072)) . '&Z=18',
            $url
        );
    }

    public function testProcessorFetchesBboxTemplatedFileUrl(): void
    {
        $bbox = XyzTile::formatBbox(XyzTile::bbox3857(1, 0, 0));
        $tileFile = $this->tempDir . '/' . $bbox . '.png';
        copy($this->tempDir . '/tile.png', $tileFile);

        $result = Processor::run(
            [
                [
                    'cacheServerName' => 'wms-src',
                    'urls' => ['file://' . $this->tempDir . '/{bbox3857}.png'],
                    'mimeType' => 'image/png',
                    'cacheBrowserTtl' => 3600,
                    'cacheServerTtl' => 86400,
                ],
            ],
            ['z' => '1', 'x' => '0', 'y' => '0', 'prefix' => null],
            $this->tempDir . '/cache',
            new MetadataScope(new Metadata($this->tempDir . '/meta.json'), 'test')
        );

        $this->assertNull($result->failure);
        $this->assertSame(file_get_contents($tileFile), $result->getData());
    }

    public function testEncodeRewritesPngToJpeg(): void
    {
        $result = Processor::run(
            [
                [
                    'cacheServerName' => 'src',
                    'urls' => ['file://' . $this->tempDir . '/tile.png'],
                    'mimeType' => 'image/png',
                    'cacheBrowserTtl' => 3600,
                    'cacheServerTtl' => 86400,
                ],
                [
                    'op' => 'encode',
                    'mimeType' => 'image/jpeg',
                    'quality' => 85,
                    'cacheServerName' => 'jpeg',
                ],
            ],
            ['z' => '1', 'x' => '0', 'y' => '0', 'prefix' => null],
            $this->tempDir . '/cache',
            new MetadataScope(new Metadata($this->tempDir . '/meta.json'), 'test')
        );

        $this->assertNull($result->failure);
        $this->assertSame('image/jpeg', $result->mimeType);
        $this->assertNotFalse(@imagecreatefromstring($result->getData()));
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($result->getData()));
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tile_proxy_src_test_' . uniqid();
        mkdir($this->tempDir);

        $img = imagecreatetruecolor(8, 8);
        $red = imagecolorallocate($img, 200, 10, 10);
        imagefill($img, 0, 0, $red);
        imagepng($img, $this->tempDir . '/tile.png');
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }
}

class TestableSrcOp extends SrcOp
{
    /**
     * @param array<string, mixed>       $cfg
     * @param array<string, string|null> $reqArgs
     */
    public static function urlForTest(array $cfg, array $reqArgs): string
    {
        $op = new self();
        $res = new Result(
            $reqArgs,
            sys_get_temp_dir() . '/unused',
            'test',
            new MetadataScope(new Metadata(sys_get_temp_dir() . '/tile_proxy_src_meta_' . uniqid()), 'test')
        );

        return $op->getSrcUrl($cfg, $res);
    }
}
