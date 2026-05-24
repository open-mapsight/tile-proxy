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

    public function testProcessorMergeWithFailureGraceful(): void
    {
        $ops = [
            [
                'cacheServerName' => 'base',
                'urls' => ['file://' . $this->tileFile],
                'mimeType' => 'image/png',
                'cacheBrowserTtl' => 3600,
                'cacheServerTtl' => 86400,
            ],
            [
                'op' => 'merge',
                'ops' => [
                    [
                        'cacheServerName' => 'overlay',
                        'urls' => ['file:///nonexistent'],
                        'mimeType' => 'image/png',
                        'cacheBrowserTtl' => 3600,
                        'cacheServerTtl' => 86400,
                    ]
                ]
            ]
        ];

        $reqArgs = ['z' => '1', 'x' => '0', 'y' => '0', 'prefix' => null];
        $cachePath = $this->tempDir . '/cache';
        $meta = new MetadataScope(new Metadata($this->tempDir . '/meta.json'), 'test');

        $result = Processor::run($ops, $reqArgs, $cachePath, $meta);

        // Current implementation will likely fail here if overlay fails
        $this->assertNull($result->failure, 'Expected graceful failure of merge');
    }

    public function testProcessorMergeTransparency(): void
    {
        $baseFile = $this->tempDir . '/base.png';
        $overlayFile = $this->tempDir . '/overlay.png';

        // Base: Solid blue
        $baseImg = imagecreatetruecolor(10, 10);
        $blue = imagecolorallocate($baseImg, 0, 0, 255);
        imagefill($baseImg, 0, 0, $blue);
        imagepng($baseImg, $baseFile);
        imagedestroy($baseImg);

        // Overlay: Semi-transparent red
        $overlayImg = imagecreatetruecolor(10, 10);
        imagealphablending($overlayImg, false);
        imagesavealpha($overlayImg, true);
        $transparentRed = imagecolorallocatealpha($overlayImg, 255, 0, 0, 64); // 50% opacity
        imagefill($overlayImg, 0, 0, $transparentRed);
        imagepng($overlayImg, $overlayFile);
        imagedestroy($overlayImg);

        $ops = [
            [
                'cacheServerName' => 'base',
                'urls' => ['file://' . $baseFile],
                'mimeType' => 'image/png',
                'cacheBrowserTtl' => 3600,
                'cacheServerTtl' => 86400,
            ],
            [
                'op' => 'merge',
                'ops' => [
                    [
                        'cacheServerName' => 'overlay',
                        'urls' => ['file://' . $overlayFile],
                        'mimeType' => 'image/png',
                        'cacheBrowserTtl' => 3600,
                        'cacheServerTtl' => 86400,
                    ]
                ]
            ]
        ];

        $reqArgs = ['z' => '1', 'x' => '0', 'y' => '0', 'prefix' => null];
        $cachePath = $this->tempDir . '/cache';
        $meta = new MetadataScope(new Metadata($this->tempDir . '/meta.json'), 'test');

        $result = Processor::run($ops, $reqArgs, $cachePath, $meta);

        $this->assertNull($result->failure, 'Expected merge to succeed');

        // Verify blending
        $img = imagecreatefromstring($result->getData());
        $rgba = imagecolorat($img, 0, 0);
        $r = ($rgba >> 16) & 0xFF;
        $b = $rgba & 0xFF;

        // Should be blended. Red component should not be 0.
        $this->assertGreaterThan(0, $r, 'Overlay should be visible (red component > 0)');
        $this->assertLessThan(255, $b, 'Base should be blended (blue component < 255)');
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
