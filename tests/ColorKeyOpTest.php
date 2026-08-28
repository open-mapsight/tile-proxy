<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\Metadata;
use OpenMapsight\TileProxy\MetadataScope;
use OpenMapsight\TileProxy\Ops\ColorKeyOp;
use OpenMapsight\TileProxy\Processor;
use OpenMapsight\TileProxy\Result;
use OpenMapsight\TileProxy\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ColorKeyOpTest extends TestCase
{
    private string $tempDir;

    public function testParseColorsReadsHexTriples(): void
    {
        $this->assertSame(
            [[0, 0, 0], [255, 255, 255], [10, 20, 30]],
            ColorKeyOp::parseColors(['#000000', '#FFFFFF', '#0a141e'])
        );
    }

    #[DataProvider('invalidColorsProvider')]
    public function testParseColorsRejectsInvalidValues(mixed $color): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('colorKey colors must be #RRGGBB');
        ColorKeyOp::parseColors([$color]);
    }

    /**
     * @return array<string, array{0:mixed}>
     */
    public static function invalidColorsProvider(): array
    {
        return [
            'short hex' => ['#000'],
            'alpha hex' => ['#00000000'],
            'missing hash' => ['000000'],
            'rgb function' => ['rgb(0,0,0)'],
            'integer' => [0],
            'list' => [[0, 0, 0]],
        ];
    }

    public function testCutImageRequiresColors(): void
    {
        $img = $this->solidImage(2, 2, 0, 0, 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('colorKey requires `colors`');

        ColorKeyOp::cutImage($img, [], 0, false);
    }

    public function testGlobalKeyRemovesEveryMatchIncludingInterior(): void
    {
        $img = $this->solidImage(5, 5, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagesetpixel($img, 2, 2, $black);

        ColorKeyOp::cutImage($img, [[0, 0, 0]], 0, false);

        $this->assertTransparent($img, 2, 2);
        $this->assertRgb($img, 0, 0, 255, 255, 255);
    }

    public function testEdgeFloodLeavesIsolatedInteriorKeyColor(): void
    {
        $img = $this->solidImage(5, 5, 255, 255, 255);
        imagesetpixel($img, 2, 2, imagecolorallocate($img, 0, 0, 0));

        ColorKeyOp::cutImage($img, [[0, 0, 0]], 0, true);

        $this->assertRgb($img, 2, 2, 0, 0, 0);
        $this->assertRgb($img, 0, 0, 255, 255, 255);
    }

    public function testEdgeFloodRemovesKeyColorConnectedToBorder(): void
    {
        $img = $this->solidImage(5, 5, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        for ($y = 0; $y < 5; $y++) {
            imagesetpixel($img, 0, $y, $black);
        }
        imagesetpixel($img, 1, 2, $black);

        ColorKeyOp::cutImage($img, [[0, 0, 0]], 0, true);

        $this->assertTransparent($img, 0, 0);
        $this->assertTransparent($img, 0, 2);
        $this->assertTransparent($img, 1, 2);
        $this->assertRgb($img, 2, 2, 255, 255, 255);
    }

    public function testEdgeFloodContinuesFromExistingTransparency(): void
    {
        $img = $this->solidImage(5, 5, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        $clear = imagecolorallocatealpha($img, 0, 0, 0, 127);
        for ($y = 1; $y <= 3; $y++) {
            for ($x = 1; $x <= 3; $x++) {
                imagesetpixel($img, $x, $y, $black);
            }
        }
        imagesetpixel($img, 2, 2, $clear);

        ColorKeyOp::cutImage($img, [[0, 0, 0]], 0, true);

        $this->assertTransparent($img, 1, 1);
        $this->assertTransparent($img, 2, 2);
        $this->assertTransparent($img, 3, 3);
        $this->assertRgb($img, 0, 0, 255, 255, 255);
    }

    public function testFuzzMatchesNearbyColors(): void
    {
        $img = $this->solidImage(1, 1, 8, 0, 0);

        ColorKeyOp::cutImage($img, [[0, 0, 0]], 8, false);

        $this->assertTransparent($img, 0, 0);
    }

    public function testFuzzDoesNotMatchOutsideTolerance(): void
    {
        $img = $this->solidImage(1, 1, 9, 0, 0);

        ColorKeyOp::cutImage($img, [[0, 0, 0]], 8, false);

        $this->assertRgb($img, 0, 0, 9, 0, 0);
    }

    public function testSoftKeyExactMatchIsFullyTransparent(): void
    {
        $img = $this->solidImage(1, 1, 255, 248, 189);

        ColorKeyOp::cutImage($img, [[255, 248, 189]], 8, false, ['soft' => true]);

        $this->assertTransparent($img, 0, 0);
    }

    public function testSoftKeyHalfwayFuzzIsPartialAlpha(): void
    {
        $img = $this->solidImage(1, 1, 4, 0, 0);

        ColorKeyOp::cutImage($img, [[0, 0, 0]], 8, false, ['soft' => true]);

        $this->assertSame(64, $this->pixel($img, 0, 0)[3]);
        $this->assertSame([4, 0, 0], array_slice($this->pixel($img, 0, 0), 0, 3));
    }

    public function testSoftKeyOutsideFuzzStaysOpaque(): void
    {
        $img = $this->solidImage(1, 1, 9, 0, 0);

        ColorKeyOp::cutImage($img, [[0, 0, 0]], 8, false, ['soft' => true]);

        $this->assertRgb($img, 0, 0, 9, 0, 0);
    }

    public function testFeatherBlursHardKnockoutIntoNeighbors(): void
    {
        $img = $this->solidImage(3, 3, 255, 255, 255);
        imagesetpixel($img, 1, 1, imagecolorallocate($img, 0, 0, 0));

        ColorKeyOp::cutImage($img, [[0, 0, 0]], 0, false, ['feather' => 1]);

        $center = $this->pixel($img, 1, 1)[3];
        $edge = $this->pixel($img, 1, 0)[3];
        $corner = $this->pixel($img, 0, 0)[3];
        $this->assertGreaterThan(0, $center);
        $this->assertGreaterThan(0, $edge);
        $this->assertGreaterThan($edge, $center);
        $this->assertGreaterThan($corner, $edge);
    }

    public function testProtectDarkerThanRestoresPartialDarkPixels(): void
    {
        $img = $this->solidImage(3, 3, 255, 248, 189);
        imagesetpixel($img, 1, 1, imagecolorallocate($img, 20, 20, 20));

        ColorKeyOp::cutImage(
            $img,
            [[255, 248, 189]],
            0,
            false,
            ['feather' => 1, 'protectDarkerThan' => 80]
        );

        $this->assertRgb($img, 1, 1, 20, 20, 20);
        $this->assertGreaterThan(0, $this->pixel($img, 0, 0)[3]);
    }

    public function testProtectDarkerThanDoesNotRestoreFullyTransparentFills(): void
    {
        $img = $this->solidImage(1, 1, 20, 20, 20);

        ColorKeyOp::cutImage($img, [[20, 20, 20]], 0, false, ['protectDarkerThan' => 80]);

        $this->assertTransparent($img, 0, 0);
    }

    public function testParseOptionsRejectsNegativeFeather(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('colorKey feather must be >= 0');
        ColorKeyOp::parseOptions(['feather' => -1]);
    }

    public function testInvokePassesSoftAndFeatherOptions(): void
    {
        $img = $this->solidImage(1, 1, 4, 0, 0);
        $res = $this->resultWithImage($img);

        $out = (new ColorKeyOp())(
            static fn (Result $result): Result => $result,
            [
                'colors' => ['#000000'],
                'fuzz' => 8,
                'fromEdges' => false,
                'soft' => true,
            ],
            $res
        );

        $processed = Utils::bytesToImage($out->getData());
        $this->assertSame(64, $this->pixel($processed, 0, 0)[3]);
    }

    public function testInvokeEncodesPngAndClearsEdgeKeyColor(): void
    {
        $img = $this->solidImage(3, 3, 255, 255, 255);
        imagesetpixel($img, 0, 0, imagecolorallocate($img, 0, 0, 0));
        imagesetpixel($img, 1, 1, imagecolorallocate($img, 0, 0, 0));
        $res = $this->resultWithImage($img);

        $out = (new ColorKeyOp())(
            static fn (Result $result): Result => $result,
            ['colors' => ['#000000']],
            $res
        );

        $this->assertSame('image/png', $out->mimeType);
        $processed = Utils::bytesToImage($out->getData());
        $this->assertTransparent($processed, 0, 0);
        $this->assertRgb($processed, 1, 1, 0, 0, 0);
    }

    public function testInvokeSkipsWorkOnCacheHit(): void
    {
        $res = $this->newResult();
        $res->mimeType = 'image/jpeg';

        $called = false;
        $out = (new ColorKeyOp())(
            static function (Result $result) use (&$called): Result {
                $called = true;
                return $result;
            },
            ['colors' => []],
            $res
        );

        $this->assertTrue($called);
        $this->assertSame('image/png', $out->mimeType);
        $this->assertTrue($out->isFromCache());
    }

    public function testProcessorRunsColorKeyOp(): void
    {
        $img = $this->solidImage(2, 2, 0, 0, 0);
        imagepng($img, $this->tempDir . '/tile.png');

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
                    'op' => 'colorKey',
                    'colors' => ['#000000'],
                    'fromEdges' => false,
                    'cacheServerName' => 'keyed',
                ],
            ],
            ['z' => '1', 'x' => '0', 'y' => '0', 'prefix' => null],
            $this->tempDir . '/cache',
            new MetadataScope(new Metadata($this->tempDir . '/meta.json'), 'test')
        );

        $this->assertNull($result->failure);
        $this->assertSame('image/png', $result->mimeType);
        $processed = Utils::bytesToImage($result->getData());
        $this->assertTransparent($processed, 0, 0);
        $this->assertTransparent($processed, 1, 1);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tile_proxy_colorkey_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    /**
     * @return \GdImage
     */
    private function solidImage(int $width, int $height, int $r, int $g, int $b)
    {
        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, $r, $g, $b, 0));

        return $img;
    }

    /**
     * @param \GdImage $img
     */
    private function resultWithImage($img): Result
    {
        $res = $this->newResult();
        $res->mimeType = 'image/png';
        $res->setData(Utils::imageToBytes('image/png', $img));

        return $res;
    }

    private function newResult(): Result
    {
        return new Result(
            ['z' => '1', 'x' => '0', 'y' => '0', 'prefix' => null],
            $this->tempDir . '/cache',
            'test',
            new MetadataScope(new Metadata($this->tempDir . '/meta.json'), 'test')
        );
    }

    /**
     * @param \GdImage $img
     */
    private function assertTransparent($img, int $x, int $y): void
    {
        $this->assertSame(127, $this->pixel($img, $x, $y)[3], "expected transparent pixel at ($x,$y)");
    }

    /**
     * @param \GdImage $img
     */
    private function assertRgb($img, int $x, int $y, int $r, int $g, int $b): void
    {
        [$pr, $pg, $pb, $pa] = $this->pixel($img, $x, $y);
        $this->assertSame([$r, $g, $b, 0], [$pr, $pg, $pb, $pa], "pixel ($x,$y)");
    }

    /**
     * @param \GdImage $img
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function pixel($img, int $x, int $y): array
    {
        $p = imagecolorat($img, $x, $y);

        return [
            ($p >> 16) & 0xFF,
            ($p >> 8) & 0xFF,
            $p & 0xFF,
            ($p >> 24) & 0x7F,
        ];
    }
}
