<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use InvalidArgumentException;
use RuntimeException;

/** @internal */
final class FileLock
{
    /** @param resource|null $handle */
    private function __construct(private mixed $handle)
    {
    }

    public static function acquire(string $path, float $timeout, bool $create = true): ?self
    {
        if (!is_finite($timeout) || $timeout < 0) {
            throw new InvalidArgumentException('Lock timeout must be finite and non-negative');
        }

        $deadline = hrtime(true) / 1e9 + $timeout;
        do {
            if ($create) {
                Utils::mkdirp(dirname($path));
            }

            $handle = @fopen($path, $create ? 'c+b' : 'r+b');
            if ($handle === false) {
                if (!$create) {
                    return null;
                }

                // An empty cache directory may have been pruned after mkdirp().
                clearstatcache(true, dirname($path));
                if (is_dir(dirname($path)) || hrtime(true) / 1e9 >= $deadline) {
                    throw new RuntimeException('Could not open lock file "' . $path . '"');
                }
            } else {
                if (flock($handle, LOCK_EX | LOCK_NB) && self::isCurrentFile($handle, $path)) {
                    return new self($handle);
                }

                fclose($handle);
            }

            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                return null;
            }

            usleep((int)min(50000, ceil($remaining * 1e6)));
        } while (true);
    }

    /** @param resource $handle */
    private static function isCurrentFile($handle, string $path): bool
    {
        clearstatcache(true, $path);
        $current = @stat($path);
        $opened = fstat($handle);

        // A cleaner may unlink metadata while another request has its old inode open.
        return $current !== false && $opened !== false
            && $current['dev'] === $opened['dev'] && $current['ino'] === $opened['ino'];
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
