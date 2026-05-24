<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

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
}
