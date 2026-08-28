<?php

declare(strict_types=1);

namespace OpenMapsight\TileProxy\Ops;

use OpenMapsight\TileProxy\Result;
use OpenMapsight\TileProxy\Utils;
use RuntimeException;

/**
 * Make nodata fill colors transparent. Default is a flood from the tile edge
 * and from already-transparent pixels, so isolated interior matches (roofs,
 * shadows) stay put.
 *
 * Optional `soft` fades alpha by Chebyshev distance to the key color instead
 * of a hard cut. Optional `feather` blurs alpha afterward. Optional
 * `protectDarkerThan` restores partial-alpha pixels darker than that
 * luminance so labels stay crisp after a soft/feathered street punch.
 */
class ColorKeyOp implements OpHandler
{
    public function __invoke(callable $next, array $cfg, Result $res): Result
    {
        if (!$res->isFromCache()) {
            Utils::assertImageMimeType($res->mimeType);
            $img = Utils::bytesToImage($res->getData());
            self::cutImage(
                $img,
                self::parseColors($cfg['colors'] ?? []),
                (int)($cfg['fuzz'] ?? 0),
                (bool)($cfg['fromEdges'] ?? true),
                self::parseOptions($cfg),
            );
            $res->setData(Utils::imageToBytes('image/png', $img));
        }

        $res->mimeType = 'image/png';

        return $next($res);
    }

    /**
     * @param list<array{0:int,1:int,2:int}> $colors
     * @param array{soft?:bool,feather?:int,protectDarkerThan?:int|null} $opts
     */
    public static function cutImage($img, array $colors, int $fuzz, bool $fromEdges, array $opts = []): void
    {
        if ($colors === []) {
            throw new RuntimeException('colorKey requires `colors`');
        }

        $opts = self::normalizeOptions($opts);
        $width = imagesx($img);
        $height = imagesy($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);

        $targets = $fromEdges
            ? self::edgeFloodTargets($img, $colors, $fuzz, $width, $height)
            : self::globalTargets($img, $colors, $fuzz, $width, $height);

        foreach ($targets as [$x, $y]) {
            [$r, $g, $b] = self::pixelRgb(imagecolorat($img, $x, $y));
            $gdAlpha = $opts['soft']
                ? self::softGdAlpha(self::minChebyshev($r, $g, $b, $colors), $fuzz)
                : 127;
            imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $r, $g, $b, $gdAlpha));
        }

        if ($opts['feather'] > 0) {
            self::featherAlpha($img, $opts['feather']);
        }

        if ($opts['protectDarkerThan'] !== null) {
            self::protectDark($img, $opts['protectDarkerThan']);
        }
    }

    /**
     * @param array<string, mixed> $cfg
     * @return array{soft:bool,feather:int,protectDarkerThan:int|null}
     */
    public static function parseOptions(array $cfg): array
    {
        return self::normalizeOptions([
            'soft' => $cfg['soft'] ?? false,
            'feather' => $cfg['feather'] ?? 0,
            'protectDarkerThan' => $cfg['protectDarkerThan'] ?? null,
        ]);
    }

    /**
     * @param list<mixed> $raw
     * @return list<array{0:int,1:int,2:int}>
     */
    public static function parseColors(array $raw): array
    {
        $out = [];
        foreach ($raw as $color) {
            if (is_string($color) && preg_match('/^#([0-9a-f]{6})$/i', $color, $m) === 1) {
                $hex = $m[1];
                $out[] = [
                    hexdec(substr($hex, 0, 2)),
                    hexdec(substr($hex, 2, 2)),
                    hexdec(substr($hex, 4, 2)),
                ];
                continue;
            }

            throw new RuntimeException('colorKey colors must be #RRGGBB');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $opts
     * @return array{soft:bool,feather:int,protectDarkerThan:int|null}
     */
    private static function normalizeOptions(array $opts): array
    {
        $feather = (int)($opts['feather'] ?? 0);
        if ($feather < 0) {
            throw new RuntimeException('colorKey feather must be >= 0');
        }

        $protect = $opts['protectDarkerThan'] ?? null;
        if ($protect !== null) {
            $protect = (int)$protect;
            if ($protect < 0 || $protect > 255) {
                throw new RuntimeException('colorKey protectDarkerThan must be 0-255');
            }
        }

        return [
            'soft' => (bool)($opts['soft'] ?? false),
            'feather' => $feather,
            'protectDarkerThan' => $protect,
        ];
    }

    /**
     * @param list<array{0:int,1:int,2:int}> $colors
     * @return list<array{0:int,1:int}>
     */
    private static function globalTargets($img, array $colors, int $fuzz, int $width, int $height): array
    {
        $targets = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (self::isKeyPixel($img, $x, $y, $colors, $fuzz)) {
                    $targets[] = [$x, $y];
                }
            }
        }

        return $targets;
    }

    /**
     * @param list<array{0:int,1:int,2:int}> $colors
     * @return list<array{0:int,1:int}>
     */
    private static function edgeFloodTargets($img, array $colors, int $fuzz, int $width, int $height): array
    {
        $visited = array_fill(0, $width * $height, false);
        $queue = [];
        $enqueue = static function (int $x, int $y) use (&$queue, &$visited, $width, $height): void {
            if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
                return;
            }
            $i = $y * $width + $x;
            if ($visited[$i]) {
                return;
            }
            $visited[$i] = true;
            $queue[] = [$x, $y];
        };

        for ($x = 0; $x < $width; $x++) {
            if (self::isKeyPixel($img, $x, 0, $colors, $fuzz)) {
                $enqueue($x, 0);
            }
            if (self::isKeyPixel($img, $x, $height - 1, $colors, $fuzz)) {
                $enqueue($x, $height - 1);
            }
        }
        for ($y = 0; $y < $height; $y++) {
            if (self::isKeyPixel($img, 0, $y, $colors, $fuzz)) {
                $enqueue(0, $y);
            }
            if (self::isKeyPixel($img, $width - 1, $y, $colors, $fuzz)) {
                $enqueue($width - 1, $y);
            }
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (self::pixelAlpha(imagecolorat($img, $x, $y)) < 127) {
                    continue;
                }
                $enqueue($x + 1, $y);
                $enqueue($x - 1, $y);
                $enqueue($x, $y + 1);
                $enqueue($x, $y - 1);
            }
        }

        $targets = [];
        $head = 0;
        while ($head < count($queue)) {
            [$x, $y] = $queue[$head++];
            if (!self::isKeyPixel($img, $x, $y, $colors, $fuzz)) {
                continue;
            }
            $targets[] = [$x, $y];
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($nx < 0 || $ny < 0 || $nx >= $width || $ny >= $height) {
                    continue;
                }
                $i = $ny * $width + $nx;
                if ($visited[$i]) {
                    continue;
                }
                if (!self::isKeyPixel($img, $nx, $ny, $colors, $fuzz)) {
                    continue;
                }
                $visited[$i] = true;
                $queue[] = [$nx, $ny];
            }
        }

        return $targets;
    }

    /**
     * @param list<array{0:int,1:int,2:int}> $colors
     */
    private static function isKeyPixel($img, int $x, int $y, array $colors, int $fuzz): bool
    {
        $pixel = imagecolorat($img, $x, $y);
        if (self::pixelAlpha($pixel) >= 127) {
            return false;
        }

        [$r, $g, $b] = self::pixelRgb($pixel);

        return self::minChebyshev($r, $g, $b, $colors) <= $fuzz;
    }

    /**
     * @param list<array{0:int,1:int,2:int}> $colors
     */
    private static function minChebyshev(int $r, int $g, int $b, array $colors): int
    {
        $best = 255;
        foreach ($colors as [$cr, $cg, $cb]) {
            $d = max(abs($r - $cr), abs($g - $cg), abs($b - $cb));
            if ($d < $best) {
                $best = $d;
            }
        }

        return $best;
    }

    private static function softGdAlpha(int $distance, int $fuzz): int
    {
        if ($distance === 0) {
            return 127;
        }

        if ($fuzz <= 0) {
            return 0;
        }

        if ($distance >= $fuzz) {
            return 0;
        }

        return (int)round((1 - $distance / $fuzz) * 127);
    }

    private static function featherAlpha($img, int $passes): void
    {
        $width = imagesx($img);
        $height = imagesy($img);
        $opacity = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $opacity[$y * $width + $x] = 127 - self::pixelAlpha(imagecolorat($img, $x, $y));
            }
        }

        for ($pass = 0; $pass < $passes; $pass++) {
            $next = $opacity;
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $sum = 0;
                    for ($dy = -1; $dy <= 1; $dy++) {
                        for ($dx = -1; $dx <= 1; $dx++) {
                            $nx = min($width - 1, max(0, $x + $dx));
                            $ny = min($height - 1, max(0, $y + $dy));
                            $k = abs($dx) + abs($dy);
                            $weight = $k === 0 ? 4 : ($k === 1 ? 2 : 1);
                            $sum += $opacity[$ny * $width + $nx] * $weight;
                        }
                    }
                    $next[$y * $width + $x] = (int)round($sum / 16);
                }
            }
            $opacity = $next;
        }

        imagealphablending($img, false);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                [$r, $g, $b] = self::pixelRgb(imagecolorat($img, $x, $y));
                $gdAlpha = 127 - $opacity[$y * $width + $x];
                imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $r, $g, $b, $gdAlpha));
            }
        }
    }

    private static function protectDark($img, int $maxLuminance): void
    {
        $width = imagesx($img);
        $height = imagesy($img);
        imagealphablending($img, false);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorat($img, $x, $y);
                $gdAlpha = self::pixelAlpha($pixel);
                if ($gdAlpha === 0 || $gdAlpha === 127) {
                    continue;
                }

                [$r, $g, $b] = self::pixelRgb($pixel);
                $luminance = (int)(($r * 299 + $g * 587 + $b * 114) / 1000);
                if ($luminance > $maxLuminance) {
                    continue;
                }

                imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $r, $g, $b, 0));
            }
        }
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function pixelRgb(int $pixel): array
    {
        return [
            ($pixel >> 16) & 0xFF,
            ($pixel >> 8) & 0xFF,
            $pixel & 0xFF,
        ];
    }

    private static function pixelAlpha(int $pixel): int
    {
        return ($pixel >> 24) & 0x7F;
    }
}
