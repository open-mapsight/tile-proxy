<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use Imagick;
use ImagickException;

class OpColorFilterUtils
{
    /**
     * @throws ImagickException
     */
    public static function reducedSaturation(string $data): string
    {
        $img = new Imagick();
        $img->readImageBlob($data);
        $img->setImageType(Imagick::IMGTYPE_TRUECOLOR);
        $img->modulateImage(100.0, 60.0, 100.0);
        $img->setImageFormat('png');
        $imageData = $img->getImageBlob();
        $img->clear();
        return $imageData;
    }

    /**
     * @throws ImagickException
     */
    public static function muted(string $data): string
    {
        $img = new Imagick();
        $img->readImageBlob($data);
        $img->setImageType(Imagick::IMGTYPE_TRUECOLOR);
        $img->modulateImage(100.0, 42.0, 100.0);
        $img->setImageColorSpace(Imagick::COLORSPACE_RGB);
        $img->setImageFormat('png');
        $ret = $img->getImageBlob();
        $img->clear();

        $imSrc = imagecreatefromstring($ret);
        $width = imagesx($imSrc);
        $height = imagesy($imSrc);
        $im = imagecreatetruecolor($width, $height);
        imagecopy($im, $imSrc, 0, 0, 0, 0, $width, $height);

        if (imageistruecolor($im)) {
            imagetruecolortopalette($im, false, 256);
        }

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $index = imagecolorat($im, $x, $y);
                $rgb = imagecolorsforindex($im, $index);
                $r = $rgb['red'];
                $g = $rgb['green'];
                $b = $rgb['blue'];
                [$h, $s, $l] = self::rgbToHsl($r, $g, $b);

                if ($h > 355 || $h < 5) {
                    // red-ish color
                    $s = $s * 70 / 100; // less saturated
                    $l = $l + 0.005; // slightly lighter
                    $l = min($l, 1);
                    $h = ($h - 5) % 360; // slightly more blueish

                    [$r, $g, $b] = self::hslToRgb($h, $s, $l);
                    imagecolorset($im, $index, (int)$r, (int)$g, (int)$b);
                }
                //elseif ($h > 52 && $h < 62) {
                //   // yellow-ish color
                //   $s = $s * 0.98;
                //   $l = $l + 0.02 - $l * 0.02;
                //   $l = $l > 1 ? 1 : $l;
                //
                //   list($r, $g, $b) = self::hslToRgb($h, $s, $l);
                //   imagecolorset($im, $index, $r, $g, $b);
                // }
            }
        }

        ob_start();
        imagepng($im);
        return ob_get_clean();
    }

    /**
     * Converts an RGB color value to HSL. Conversion formula
     * adapted from http://en.wikipedia.org/wiki/HSL_color_space.
     * Assumes r, g, and b are contained in the set [0, 255] and
     * returns h, s, and l in the set [0, 1].
     *
     * @param number $r The red color value
     * @param number $g The green color value
     * @param number $b The blue color value
     * @return  number[]         The HSL representation
     */
    public static function rgbToHsl($r, $g, $b): array
    {
        $h = 0;
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        if ($d === 0) {
            $h = $s = 0; // achromatic
        } else {
            $s = $d / (1 - abs(2 * $l - 1));
            switch ($max) {
                case $r:
                    $h = 60 * fmod((($g - $b) / $d), 6);
                    if ($b > $g) {
                        $h += 360;
                    }
                    break;
                case $g:
                    $h = 60 * (($b - $r) / $d + 2);
                    break;
                case $b:
                    $h = 60 * (($r - $g) / $d + 4);
                    break;
            }
        }

        return [round($h, 2), round($s, 2), round($l, 2)];
    }

    /**
     * Converts an HSL color value to RGB. Conversion formula
     * adapted from http://en.wikipedia.org/wiki/HSL_color_space.
     * Assumes h, s, and l are contained in the set [0, 1] and
     * returns r, g, and b in the set [0, 255].
     *
     * @param number $h The hue
     * @param number $s The saturation
     * @param number $l The lightness
     * @return  number[]         The RGB representation
     */
    public static function hslToRgb($h, $s, $l): array
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod(($h / 60), 2) - 1));
        $m = $l - ($c / 2);
        if ($h < 60) {
            $r = $c;
            $g = $x;
            $b = 0;
        } else if ($h < 120) {
            $r = $x;
            $g = $c;
            $b = 0;
        } else if ($h < 180) {
            $r = 0;
            $g = $c;
            $b = $x;
        } else if ($h < 240) {
            $r = 0;
            $g = $x;
            $b = $c;
        } else if ($h < 300) {
            $r = $x;
            $g = 0;
            $b = $c;
        } else {
            $r = $c;
            $g = 0;
            $b = $x;
        }
        $r = ($r + $m) * 255;
        $g = ($g + $m) * 255;
        $b = ($b + $m) * 255;

        return [floor($r), floor($g), floor($b)];
    }

    public static function manipulatePixelsHsl($imageData, $callback): false|string
    {
        $image = imagecreatefromstring($imageData);
        [$height, $width] = getimagesizefromstring($imageData);

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                [$r, $g, $b] = self::colorToRgb(imagecolorat($image, $x, $y));
                [$h, $s, $l] = self::rgbToHsl($r, $g, $b);
                [$newH, $newS, $newL] = $callback($h, $s, $l);
                [$newR, $newG, $newB] = self::hslToRgb($newH, $newS, $newL);
                imagesetpixel($image, $x, $y, self::rgbToColor($newR, $newG, $newB));
            }
        }

        ob_start();
        imagepng($image);
        return ob_get_clean();
    }

    public static function colorToRgb($color): array
    {
        $r = ($color >> 16) & 0xFF;
        $g = ($color >> 8) & 0xFF;
        $b = $color & 0xFF;

        return [$r, $g, $b];
    }

    public static function rgbToColor($r, $g, $b): int
    {
        return (($r & 0x0ff) << 16) | (($g & 0x0ff) << 8) | ($b & 0x0ff);
    }
}
