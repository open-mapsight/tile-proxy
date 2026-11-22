<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use RuntimeException;

class DiskCache
{
    public static function readFresh(string $path, int $ttlSeconds): ?string
    {
        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        if ($mtime === false || time() - $mtime > $ttlSeconds) {
            return null;
        }

        return static::read($path);
    }

    public static function read(string $path): ?string
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            return null;
        }

        return $data;
    }

    public static function write(string $path, string $data): void
    {
        Utils::writeToFile($path, $data);
    }

    /** @internal */
    public static function writeAtomically(string $path, string $data): void
    {
        $directory = dirname($path);
        Utils::mkdirp($directory);
        $temp = @tempnam($directory, '.write-');
        if ($temp === false) {
            throw new RuntimeException('Could not create temporary cache file in "' . $directory . '"');
        }

        try {
            if (@file_put_contents($temp, $data) !== strlen($data) || !@rename($temp, $path)) {
                throw new RuntimeException('Could not replace cache file "' . $path . '"');
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
        clearstatcache(true, $path);
    }
}
