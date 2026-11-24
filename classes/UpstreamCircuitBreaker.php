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

    public static function withHttpTimeout(string $url, array $httpConfig): array
    {
        if (in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $timeout = array_key_exists('timeout', $httpConfig) ? $httpConfig['timeout'] : 30;
            // cURL represents the transport timeout in whole milliseconds.
            if (!is_numeric($timeout) || !is_finite((float)$timeout) || (float)$timeout < 0.001) {
                throw new InvalidArgumentException(
                    'upstreamHttp.timeout must be finite and at least 0.001 seconds with the circuit breaker'
                );
            }
            $httpConfig['timeout'] = (float)$timeout;
        }

        return $httpConfig;
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
        $reinitialized = false;

        try {
            $lock = FileLock::acquireStable($path . '.lock');
            try {
                $state = $this->readState($path, $reinitialized);
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
                if ($reinitialized) {
                    Log::warning('Upstream circuit state reinitialized', ['circuit' => $key]);
                }
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
        $reinitialized = false;
        try {
            $lock = FileLock::acquireStable($path . '.lock');
            try {
                $state = $this->readState($path, $reinitialized);
                if ($state['generation'] !== $generation) {
                    return;
                }

                $now = time();
                if ($probe) {
                    $state['openUntil'] = $temporary ? $now + min($this->openTtl, PHP_INT_MAX - $now) : 0;
                    $state['count'] = $state['windowStart'] = 0;
                    $event = $temporary ? 'Upstream circuit reopened' : 'Upstream circuit recovered';
                } else {
                    if ($state['count'] === 0 || $now - $state['windowStart'] >= $this->window) {
                        $state['count'] = 0;
                        $state['windowStart'] = $now;
                    }
                    if (++$state['count'] >= $this->threshold) {
                        $state['openUntil'] = $now + min($this->openTtl, PHP_INT_MAX - $now);
                        $event = 'Upstream circuit opened';
                    }
                }
                if ($event !== null) {
                    $state['generation'] = bin2hex(random_bytes(16));
                }

                DiskCache::writeAtomically($path . '.json', json_encode($state, JSON_THROW_ON_ERROR));
            } finally {
                $lock->release();
                if ($reinitialized) {
                    Log::warning('Upstream circuit state reinitialized', ['circuit' => $key]);
                }
            }
        } catch (RuntimeException) {
            Log::warning('Upstream circuit state unavailable', ['circuit' => $key]);
            return;
        }

        if ($event !== null) {
            Log::warning($event, ['circuit' => $key]);
        }
    }

    /** @return array{generation: string, count: int, windowStart: int, openUntil: int} */
    private function readState(string $path, bool &$reinitialized): array
    {
        $statePath = $path . '.json';
        $initial = ['generation' => '', 'count' => 0, 'windowStart' => 0, 'openUntil' => 0];
        $raw = DiskCache::read($statePath);
        if ($raw === null) {
            clearstatcache(true, $statePath);
            if (@lstat($statePath) !== false) {
                throw new RuntimeException('Could not read upstream circuit state');
            }

            return $initial;
        }

        $state = json_decode($raw, true);
        if (!is_string($state['generation'] ?? null)
            || !is_int($state['count'] ?? null) || $state['count'] < 0
            || !is_int($state['windowStart'] ?? null) || $state['windowStart'] < 0
            || !is_int($state['openUntil'] ?? null) || $state['openUntil'] < 0
        ) {
            $initial['generation'] = bin2hex(random_bytes(16));
            DiskCache::writeAtomically($statePath, json_encode($initial, JSON_THROW_ON_ERROR));
            $reinitialized = true;
            return $initial;
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
