<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use DirectoryIterator;
use InvalidArgumentException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use UnexpectedValueException;

final class CachePruner
{
    /** Delete raster cache files older than maxAgeSeconds; return the number of files deleted. */
    public static function prune(string $cacheServerPath, int $maxAgeSeconds): int
    {
        if ($maxAgeSeconds <= 0) {
            throw new InvalidArgumentException('Cache retention must be positive');
        }

        if (!is_dir($cacheServerPath)) {
            return 0;
        }

        $cutoff = time() - $maxAgeSeconds;
        $deleted = 0;
        // Mapbox asset writers do not share raster tile locks, even in the same cache root.
        $mapboxCachePath = rtrim($cacheServerPath, '/') . '/mapbox-style-proxy';
        $files = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($cacheServerPath, RecursiveDirectoryIterator::SKIP_DOTS),
                static fn (SplFileInfo $file): bool => $file->getPathname() !== $mapboxCachePath
            ),
            RecursiveIteratorIterator::CHILD_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($files as $file) {
            if ($file->isLink()) {
                continue;
            }

            if ($file->isDir()) {
                $deleted += self::pruneDirectory($file->getPathname(), $cutoff);
                // Non-empty directories and directories in use are left in place.
                @rmdir($file->getPathname());
            }
        }

        $deleted += self::pruneDirectory($cacheServerPath, $cutoff);
        return $deleted;
    }

    private static function pruneDirectory(string $path, int $cutoff): int
    {
        try {
            $files = new DirectoryIterator($path);
        } catch (UnexpectedValueException $error) {
            // Another pruner may have removed this empty directory during traversal.
            if (!is_dir($path)) {
                return 0;
            }
            throw $error;
        }

        $groups = [];
        foreach ($files as $file) {
            if ($file->isLink() || !$file->isFile()) {
                continue;
            }

            if (preg_match('/^(\d+)-\.metadata$/D', $file->getFilename(), $matches) === 1) {
                $groups[$matches[1]]['metadata'] = $file->getPathname();
            } elseif (preg_match('/^(\d+)-.+-\d+$/D', $file->getFilename(), $matches) === 1) {
                $groups[$matches[1]]['tiles'][] = $file->getPathname();
            }
        }

        $deleted = 0;
        foreach ($groups as $group) {
            if (isset($group['metadata'])) {
                $deleted += self::pruneTile($group['metadata'], $group['tiles'] ?? [], $cutoff);
            }
        }

        return $deleted;
    }

    /** @param list<string> $tiles */
    private static function pruneTile(string $metadataPath, array $tiles, int $cutoff): int
    {
        $lock = FileLock::acquire($metadataPath, 0, false);
        if ($lock === null) {
            return 0;
        }

        try {
            $deleted = 0;
            $hasTiles = false;

            foreach ($tiles as $tilePath) {
                if (self::isExpired($tilePath, $cutoff)) {
                    self::delete($tilePath);
                    ++$deleted;
                } else {
                    $hasTiles = true;
                }
            }

            // A failed request can checkpoint a new namespace after the scan without touching
            // metadata. Recheck the directory under the lock before removing its lock file.
            if (!$hasTiles && self::isExpired($metadataPath, $cutoff) && !self::hasTiles($metadataPath)) {
                self::delete($metadataPath);
                ++$deleted;
            }

            return $deleted;
        } finally {
            $lock->release();
        }
    }

    private static function hasTiles(string $metadataPath): bool
    {
        $tilePattern = '/^' . preg_quote(basename($metadataPath, '-.metadata'), '/') . '-.+-\d+$/D';
        foreach (new DirectoryIterator(dirname($metadataPath)) as $file) {
            if (preg_match($tilePattern, $file->getFilename()) === 1 && !$file->isLink() && $file->isFile()) {
                return true;
            }
        }

        return false;
    }

    private static function isExpired(string $path, int $cutoff): bool
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            return false;
        }
        $mtime = @filemtime($path);
        return $mtime !== false && $mtime < $cutoff;
    }

    private static function delete(string $path): void
    {
        if (!@unlink($path)) {
            throw new RuntimeException('Could not delete cache file "' . $path . '"');
        }
    }
}
