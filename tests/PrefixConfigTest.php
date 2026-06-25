<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\Base;
use OpenMapsight\TileProxy\Metadata;
use OpenMapsight\TileProxy\MetadataScope;
use OpenMapsight\TileProxy\Processor;
use OpenMapsight\TileProxy\UserException;
use PHPUnit\Framework\TestCase;

class PrefixConfigTest extends TestCase
{
    private string $tempDir;

    /** @return array<string, mixed> */
    private function prefixConfig(): array
    {
        return [
            'defaultPrefix' => 'default',
            'prefixArgName' => 'prefix',
            'allowedPrefixes' => ['default', 'style-a', 'style-b', 'none'],
        ];
    }

    public function testPrefixArgUsesPrefixQueryParam(): void
    {
        $_GET = ['x' => '0', 'y' => '0', 'z' => '1', 'prefix' => 'style-a'];

        $reqArgs = TestableBase::getReqArgsForTest($this->prefixConfig());

        $this->assertSame('style-a', $reqArgs['prefix']);
    }

    public function testPrefixArgFallsBackToDefaultPrefix(): void
    {
        $_GET = ['x' => '0', 'y' => '0', 'z' => '1'];

        $reqArgs = TestableBase::getReqArgsForTest($this->prefixConfig());

        $this->assertSame('default', $reqArgs['prefix']);
    }

    public function testPrefixArgRejectsUnknownPrefix(): void
    {
        $_GET = ['x' => '0', 'y' => '0', 'z' => '1', 'prefix' => 'unknown'];

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Prefix "unknown" not allowed');

        TestableBase::getReqArgsForTest($this->prefixConfig());
    }

    public function testMergeUsesPrefixInOverlayUrlAndWritesOverlayCache(): void
    {
        $baseFile = $this->createSolidPng($this->tempDir . '/base.png', 0, 0, 255);
        $styleAOverlayFile = $this->createSolidPng($this->tempDir . '/style-a.png', 255, 0, 0);
        $styleBOverlayFile = $this->createSolidPng($this->tempDir . '/style-b.png', 0, 255, 0);

        $ops = [
            [
                'cacheServerName' => 'src',
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
                        'urls' => ['file://' . $this->tempDir . '/{prefix}.png'],
                        'mimeType' => 'image/png',
                        'cacheBrowserTtl' => 3600,
                        'cacheServerTtl' => 86400,
                    ],
                ],
            ],
        ];

        $styleACachePath = $this->tempDir . '/cache/style-a/1/0/0';
        $styleAMeta = new MetadataScope(new Metadata($styleACachePath . '-.metadata'), 'test');
        $styleAResult = Processor::run(
            $ops,
            ['z' => '1', 'x' => '0', 'y' => '0', 'prefix' => 'style-a'],
            $styleACachePath,
            $styleAMeta
        );

        $this->assertNull($styleAResult->failure);
        $styleAOverlayCache = $styleACachePath . '-overlay-0';
        $this->assertFileExists($styleAOverlayCache);
        $this->assertSame(file_get_contents($styleAOverlayFile), file_get_contents($styleAOverlayCache));

        $styleBCachePath = $this->tempDir . '/cache/style-b/1/0/0';
        $styleBMeta = new MetadataScope(new Metadata($styleBCachePath . '-.metadata'), 'test');
        $styleBResult = Processor::run(
            $ops,
            ['z' => '1', 'x' => '0', 'y' => '0', 'prefix' => 'style-b'],
            $styleBCachePath,
            $styleBMeta
        );

        $this->assertNull($styleBResult->failure);
        $styleBOverlayCache = $styleBCachePath . '-overlay-0';
        $this->assertFileExists($styleBOverlayCache);
        $this->assertSame(file_get_contents($styleBOverlayFile), file_get_contents($styleBOverlayCache));
        $this->assertNotSame(
            file_get_contents($styleAOverlayCache),
            file_get_contents($styleBOverlayCache)
        );
    }

    private function createSolidPng(string $path, int $r, int $g, int $b): string
    {
        $img = imagecreatetruecolor(10, 10);
        $color = imagecolorallocate($img, $r, $g, $b);
        imagefill($img, 0, 0, $color);
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tile_proxy_prefix_test_' . uniqid();
        mkdir($this->tempDir);
        mkdir($this->tempDir . '/cache', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tempDir);
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir, SCANDIR_SORT_NONE) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $filePath = $dir . '/' . $file;
            if (is_dir($filePath)) {
                $this->deleteTree($filePath);
            } else {
                unlink($filePath);
            }
        }

        rmdir($dir);
    }
}

class TestableBase extends Base
{
    /** @param array<string, mixed> $cfg */
    public static function getReqArgsForTest(array $cfg): array
    {
        return static::getReqArgs($cfg);
    }
}
