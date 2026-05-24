<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\Metadata;
use OpenMapsight\TileProxy\MetadataScope;
use OpenMapsight\TileProxy\Processor;
use PHPUnit\Framework\TestCase;

class ProcessorTest extends TestCase
{
    private string $tempDir;
    private string $tileFile;

    public function testProcessorRunSimple(): void
    {
        $ops = [
            [
                'cacheServerName' => 'testSource',
                'urls' => ['file://' . $this->tileFile],
                'mimeType' => 'image/png',
                'cacheBrowserTtl' => 3600,
                'cacheServerTtl' => 86400,
            ]
        ];

        $reqArgs = ['z' => '1', 'x' => '0', 'y' => '0', 'prefix' => null];
        $cachePath = $this->tempDir . '/cache';
        $meta = new MetadataScope(new Metadata($this->tempDir . '/meta.json'), 'test');

        $result = Processor::run($ops, $reqArgs, $cachePath, $meta);

        $this->assertNull($result->failure);
        $this->assertEquals('image/png', $result->mimeType);
        $this->assertEquals(3600, $result->cacheBrowserTtl);
        $this->assertNotNull($result->getData());
        $this->assertEquals(file_get_contents($this->tileFile), $result->getData());
    }

    public function testProcessorWithOps(): void
    {
        $ops = [
            [
                'cacheServerName' => 'testSource',
                'urls' => ['file://' . $this->tileFile],
                'mimeType' => 'image/png',
                'cacheBrowserTtl' => 3600,
                'cacheServerTtl' => 86400,
            ],
            [
                'op' => 'colorFilter',
                'filter' => 'reducedSaturation',
                'cacheServerName' => 'testFilter',
            ]
        ];

        if (!extension_loaded('imagick')) {
            // We can skip this part or the whole test if we want to be strict,
            // but let's try to run it if possible.
            $this->markTestSkipped('Imagick not loaded, skipping op test');
        }

        $reqArgs = ['z' => '1', 'x' => '0', 'y' => '0', 'prefix' => null];
        $cachePath = $this->tempDir . '/cache';
        $meta = new MetadataScope(new Metadata($this->tempDir . '/meta.json'), 'test');

        $result = Processor::run($ops, $reqArgs, $cachePath, $meta);

        $this->assertNull($result->failure);
        $this->assertEquals('image/png', $result->mimeType);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tile_proxy_test_' . uniqid();
        mkdir($this->tempDir);
        $this->tileFile = $this->tempDir . '/tile.png';

        // Create a dummy 1x1 png tile
        $img = imagecreatetruecolor(1, 1);
        imagepng($img, $this->tileFile);
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }
}
