<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

class DiskCache
{
    public static function readFresh(string $path, int $ttlSeconds): ?string
    {
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
}
