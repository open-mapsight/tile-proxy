<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use JsonException;
use OpenMapsight\TileProxy\Utils;
use PHPUnit\Framework\TestCase;

class UtilsTest extends TestCase
{
    public function testParseJsoncStringRemovesComments(): void
    {
        $jsonc = <<<JSON
        {
            // single line comment
            "key": "value", /* block
            comment */
            "another": "val" // end of line comment
        }
        JSON;

        $expected = [
            'key' => 'value',
            'another' => 'val'
        ];

        $this->assertEquals($expected, Utils::parseJsoncString($jsonc));
    }

    public function testParseJsoncStringThrowsOnInvalidJson(): void
    {
        $this->expectException(JsonException::class);
        Utils::parseJsoncString('{ "key": invalid }');
    }

    public function testParseJsoncStringRequiresObjectRoot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuration JSON must be an object');
        Utils::parseJsoncString('"just a string"');
    }

    public function testAssertImageMimeTypePassesForSupportedTypes(): void
    {
        Utils::assertImageMimeType('image/png');
        Utils::assertImageMimeType('image/jpeg');
        Utils::assertImageMimeType('image/webp');
        $this->assertTrue(true); // Should reach here without exception
    }

    public function testAssertImageMimeTypeThrowsForUnsupportedType(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported image mime type: "image/tiff"');
        Utils::assertImageMimeType('image/tiff');
    }

    public function testMkdirpCreatesDirectory(): void
    {
        $testDir = __DIR__ . '/../temp_test_dir/nested/deeply';
        if (is_dir($testDir)) {
            @rmdir($testDir);
        }

        Utils::mkdirp($testDir);
        $this->assertDirectoryExists($testDir);

        // Cleanup
        @rmdir(__DIR__ . '/../temp_test_dir/nested/deeply');
        @rmdir(__DIR__ . '/../temp_test_dir/nested');
        @rmdir(__DIR__ . '/../temp_test_dir');
    }

    public function testWriteToFileWritesData(): void
    {
        $path = sys_get_temp_dir() . '/tile_proxy_write_test_' . uniqid() . '/file.bin';

        Utils::writeToFile($path, 'hello');

        $this->assertSame('hello', file_get_contents($path));

        unlink($path);
        rmdir(dirname($path));
    }

    public function testMapAssetBasePathPrefersNewKeyOverAlias(): void
    {
        $this->assertSame(
            '/map-assets',
            Utils::mapAssetBasePath([
                'mapAssetBasePath' => '/map-assets',
                'publicBasePath' => '/legacy',
            ])
        );
    }

    public function testMapAssetBasePathAcceptsPublicBasePathAlias(): void
    {
        $this->assertSame('/map-assets', Utils::mapAssetBasePath(['publicBasePath' => '/map-assets/']));
    }

    public function testWriteToFileThrowsWhenWriteFails(): void
    {
        $dir = sys_get_temp_dir() . '/tile_proxy_write_dir_' . uniqid();
        mkdir($dir);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not write to file');
            Utils::writeToFile($dir, 'data');
        } finally {
            rmdir($dir);
        }
    }
}
