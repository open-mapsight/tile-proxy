<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\OpColorFilterUtils;
use PHPUnit\Framework\TestCase;

class OpColorFilterUtilsTest extends TestCase
{
    public function testRgbToHsl(): void
    {
        // White
        $this->assertEquals([0.0, 0.0, 1.0], OpColorFilterUtils::rgbToHsl(255, 255, 255));
        // Black
        $this->assertEquals([0.0, 0.0, 0.0], OpColorFilterUtils::rgbToHsl(0, 0, 0));
        // Red
        $this->assertEquals([0.0, 1.0, 0.5], OpColorFilterUtils::rgbToHsl(255, 0, 0));
    }

    public function testHslToRgb(): void
    {
        // White
        $this->assertEquals([255, 255, 255], OpColorFilterUtils::hslToRgb(0.0, 0.0, 1.0));
        // Black
        $this->assertEquals([0, 0, 0], OpColorFilterUtils::hslToRgb(0.0, 0.0, 0.0));
        // Red
        $this->assertEquals([255, 0, 0], OpColorFilterUtils::hslToRgb(0.0, 1.0, 0.5));
    }

    public function testColorToRgbAndBack(): void
    {
        $r = 100;
        $g = 150;
        $b = 200;
        $color = OpColorFilterUtils::rgbToColor($r, $g, $b);
        $this->assertEquals([$r, $g, $b], OpColorFilterUtils::colorToRgb($color));
    }

    public function testReducedSaturation(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension not loaded');
        }
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not loaded');
        }

        $img = imagecreatetruecolor(1, 1);
        imagesetpixel($img, 0, 0, imagecolorallocate($img, 255, 0, 0)); // Pure red
        ob_start();
        imagepng($img);
        $data = ob_get_clean();

        $processedData = OpColorFilterUtils::reducedSaturation($data);
        $processedImg = imagecreatefromstring($processedData);
        $rgb = imagecolorat($processedImg, 0, 0);
        $colors = imagecolorsforindex($processedImg, $rgb);

        // Saturation should be reduced. Original red (255,0,0) HSL is (0, 1, 0.5)
        // reducedSaturation uses 0.1 saturation.
        // HSL (0, 0.1, 0.5) -> RGB (140, 115, 115) roughly
        $this->assertLessThan(255, $colors['red']);
        $this->assertGreaterThan(0, $colors['green']);
        $this->assertGreaterThan(0, $colors['blue']);
    }
}
