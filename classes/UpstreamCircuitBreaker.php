<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use InvalidArgumentException;
use RuntimeException;

/** @internal */
final class UpstreamCircuitBreaker
{
    public const DIRECTORY = '.upstream-circuits';

    private readonly int $threshold;
    private readonly int $window;
    private readonly int $openTtl;

    public function __construct(private readonly string $cacheRoot, array $config)
    {
        $this->threshold = self::positiveInt($config, 'failureThreshold', 5);
        $this->window = self::positiveInt($config, 'failureWindow', 30);
        $this->openTtl = self::positiveInt($config, 'openTtl', 30);
    }

    /**
     * @param callable(): UpstreamFetchResult $fetch
     * @return UpstreamFetchResult|null Null means the circuit skipped this fetch.
     */
    public function fetch(string $scope, string $url, callable $fetch): ?UpstreamFetchResult
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!isset($parts['host']) || !in_array($scheme, ['http', 'https'], true)) {
            return $fetch();
        }

        $origin = [$scheme, strtolower($parts['host']), $parts['port'] ?? ($scheme === 'https' ? 443 : 80)];
        $key = hash('sha256', serialize([$scope, $origin]));
        $path = rtrim($this->cacheRoot, '/') . '/' . self::DIRECTORY . '/' . $key;
        $probe = null;

        try {
            $lock = $this->lockState($path);
            try {
                $state = $this->readState($path);
                if ($state['openUntil'] > time()) {
                    return null;
                }
                if ($state['openUntil'] > 0) {
                    $probe = FileLock::acquire($path . '.probe.lock', 0);
                    if ($probe === null) {
                        return null;
                    }
                }
            } finally {
                $lock->release();
            }
        } catch (RuntimeException) {
            Log::warning('Upstream circuit state unavailable', ['circuit' => $key]);
            return $fetch();
        }

        try {
            $result = $fetch();
            $this->recordResult($path, $key, $state['generation'], $probe !== null, $result);
            return $result;
        } finally {
            $probe?->release();
        }
    }

    private function recordResult(
        string $path, string $key, string $generation, bool $probe, UpstreamFetchResult $result
    ): void {
        $temporary = !$result->isSuccess() && !$result->isPermanentClientError();
        if (!$probe && !$temporary) {
            return;
        }

        $event = null;
        try {
            $lock = $this->lockState($path);
            try {
                $state = $this->readState($path);
                if ($state['generation'] !== $generation) {
                    return;
                }

                $now = time();
                if ($probe) {
                    $state['openUntil'] = $temporary ? $now + $this->openTtl : 0;
                    $state['count'] = $state['windowStart'] = 0;
                    $event = $temporary ? 'Upstream circuit reopened' : 'Upstream circuit recovered';
                } else {
                    if ($state['count'] === 0 || $now - $state['windowStart'] >= $this->window) {
                        $state['count'] = 0;
                        $state['windowStart'] = $now;
                    }
                    if (++$state['count'] >= $this->threshold) {
                        $state['openUntil'] = $now + $this->openTtl;
                        $event = 'Upstream circuit opened';
                    }
                }
                if ($event !== null) {
                    $state['generation'] = bin2hex(random_bytes(16));
                }

                DiskCache::writeAtomically($path . '.json', json_encode($state, JSON_THROW_ON_ERROR));
            } finally {
                $lock->release();
            }
        } catch (RuntimeException) {
            Log::warning('Upstream circuit state unavailable', ['circuit' => $key]);
            return;
        }

        if ($event !== null) {
            Log::warning($event, ['circuit' => $key]);
        }
    }

    private function lockState(string $path): FileLock
    {
        return FileLock::acquire($path . '.lock', 0.05)
            ?? throw new RuntimeException('Timed out acquiring upstream circuit state lock');
    }

    /** @return array{generation: string, count: int, windowStart: int, openUntil: int} */
    private function readState(string $path): array
    {
        $raw = DiskCache::read($path . '.json');
        if ($raw === null && !is_file($path . '.json')) {
            return ['generation' => '', 'count' => 0, 'windowStart' => 0, 'openUntil' => 0];
        }

        $state = $raw === null ? null : json_decode($raw, true);
        if (!is_string($state['generation'] ?? null)
            || !is_int($state['count'] ?? null)
            || !is_int($state['windowStart'] ?? null)
            || !is_int($state['openUntil'] ?? null)
        ) {
            throw new RuntimeException('Invalid upstream circuit state');
        }

        return $state;
    }

    private static function positiveInt(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;
        if (!is_int($value) || $value <= 0) {
            throw new InvalidArgumentException("upstreamCircuitBreaker.$key must be a positive integer");
        }

        return $value;
    }
}
