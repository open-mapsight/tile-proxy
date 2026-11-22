<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use InvalidArgumentException;

/** @internal */
final class TileNotFoundPolicy
{
    private function __construct(public readonly int $serverTtl, private readonly int $browserTtl)
    {
    }

    public static function fromConfig(array $config): ?self
    {
        if (!array_key_exists('cacheServer404Ttl', $config) && !array_key_exists('cacheBrowser404Ttl', $config)) {
            return null;
        }

        return new self(
            self::readTtl($config, 'cacheServer404Ttl'),
            self::readTtl($config, 'cacheBrowser404Ttl'),
        );
    }

    public function remainingServerTtl(int $recordedAt): int
    {
        return max(0, $this->serverTtl - max(0, time() - $recordedAt));
    }

    public function browserTtl(int $recordedAt): int
    {
        return $this->serverTtl === 0
            ? $this->browserTtl
            : min($this->browserTtl, $this->remainingServerTtl($recordedAt));
    }

    private static function readTtl(array $config, string $key): int
    {
        $value = array_key_exists($key, $config) ? $config[$key] : 0;
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException($key . ' must be a non-negative integer');
        }

        return $value;
    }
}
