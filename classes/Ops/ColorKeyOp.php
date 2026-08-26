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
            );
            $res->setData(Utils::imageToBytes('image/png', $img));
        }

        $res->mimeType = 'image/png';

        return $next($res);
    }

    /**
     * @param list<array{0:int,1:int,2:int}> $colors
     */
    public static function cutImage($img, array $colors, int $fuzz, bool $fromEdges): void
    {
        if ($colors === []) {
            throw new RuntimeException('colorKey requires `colors`');
        }

        $width = imagesx($img);
        $height = imagesy($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $clear = imagecolorallocatealpha($img, 0, 0, 0, 127);

        $isKey = static function (int $r, int $g, int $b) use ($colors, $fuzz): bool {
            foreach ($colors as [$cr, $cg, $cb]) {
                if (
                    abs($r - $cr) <= $fuzz
                    && abs($g - $cg) <= $fuzz
                    && abs($b - $cb) <= $fuzz
                ) {
                    return true;
                }
            }

            return false;
        };

        $rgba = static function (int $pixel): array {
            return [
                ($pixel >> 16) & 0xFF,
                ($pixel >> 8) & 0xFF,
                $pixel & 0xFF,
                ($pixel >> 24) & 0x7F,
            ];
        };

        $match = static function (int $x, int $y) use ($img, $rgba, $isKey): bool {
            [$r, $g, $b, $a] = $rgba(imagecolorat($img, $x, $y));

            return $a < 127 && $isKey($r, $g, $b);
        };

        if (!$fromEdges) {
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    if ($match($x, $y)) {
                        imagesetpixel($img, $x, $y, $clear);
                    }
                }
            }

            return;
        }

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
            if ($match($x, 0)) {
                $enqueue($x, 0);
            }
            if ($match($x, $height - 1)) {
                $enqueue($x, $height - 1);
            }
        }
        for ($y = 0; $y < $height; $y++) {
            if ($match(0, $y)) {
                $enqueue(0, $y);
            }
            if ($match($width - 1, $y)) {
                $enqueue($width - 1, $y);
            }
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $a = $rgba(imagecolorat($img, $x, $y))[3];
                if ($a < 127) {
                    continue;
                }
                $enqueue($x + 1, $y);
                $enqueue($x - 1, $y);
                $enqueue($x, $y + 1);
                $enqueue($x, $y - 1);
            }
        }

        $head = 0;
        while ($head < count($queue)) {
            [$x, $y] = $queue[$head++];
            if (!$match($x, $y)) {
                continue;
            }
            imagesetpixel($img, $x, $y, $clear);
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
                if (!$match($nx, $ny)) {
                    continue;
                }
                $visited[$i] = true;
                $queue[] = [$nx, $ny];
            }
        }
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
}
