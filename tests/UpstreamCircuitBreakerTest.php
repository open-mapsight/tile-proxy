<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\FileLock;
use OpenMapsight\TileProxy\UpstreamCircuitBreaker;
use OpenMapsight\TileProxy\UpstreamFetchResult;
use PHPUnit\Framework\TestCase;

class UpstreamCircuitBreakerTest extends TestCase
{
    private const FIRST_TILE = 'https://origin.test/a';
    private const OTHER_TILE = 'https://ORIGIN.test:443/b';
    private string $root;
    private array $workers = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tile_proxy_circuit_' . uniqid();
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }

        $dir = $this->root . '/' . UpstreamCircuitBreaker::DIRECTORY;
        foreach (glob($dir . '/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
        rmdir($this->root);
    }

    public function testThresholdAndWindowAreSharedAcrossTilesButIsolateOriginsAndSources(): void
    {
        $first = $this->breaker(2);
        $second = $this->breaker(2);
        $first->fetch('src', self::FIRST_TILE, self::failTemporarily(...));

        $path = $this->statePath();
        $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $state['windowStart'] = time() - 31;
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));

        $second->fetch('src', self::OTHER_TILE, self::failTemporarily(...));
        $first->fetch('src', self::FIRST_TILE, static fn () => new UpstreamFetchResult(null, 404, false));
        self::assertTrue($second->fetch('src', self::OTHER_TILE, self::succeed(...))->isSuccess());

        $first->fetch('src', self::FIRST_TILE, self::failTemporarily(...));
        self::assertNull($second->fetch('src', self::OTHER_TILE, self::unexpectedFetch(...)));
        self::assertTrue($second->fetch('src', 'https://mirror.test/a', self::succeed(...))->isSuccess());
        self::assertTrue($second->fetch('other', self::FIRST_TILE, self::succeed(...))->isSuccess());
    }

    public function testHalfOpenAllowsOneProbeAndIgnoresOldCompletions(): void
    {
        $first = $this->breaker(1);
        $second = $this->breaker(1);

        $first->fetch('src', self::FIRST_TILE, function () use ($second): UpstreamFetchResult {
            $second->fetch('src', self::OTHER_TILE, self::failTemporarily(...));
            $this->expireCircuit();
            $second->fetch('src', self::OTHER_TILE, function (): UpstreamFetchResult {
                self::assertNull($this->breaker(1)->fetch('src', self::FIRST_TILE, self::unexpectedFetch(...)));
                return self::succeed();
            });

            return self::failTemporarily();
        });

        self::assertTrue($first->fetch('src', self::FIRST_TILE, self::succeed(...))->isSuccess());
    }

    public function testFailedProbeReopensAndPermanentClientErrorCloses(): void
    {
        $breaker = $this->breaker(1);
        $breaker->fetch('src', self::FIRST_TILE, self::failTemporarily(...));
        $this->expireCircuit();
        $breaker->fetch('src', self::OTHER_TILE, self::failTemporarily(...));
        self::assertNull($breaker->fetch('src', self::FIRST_TILE, self::unexpectedFetch(...)));
        $this->expireCircuit();
        $breaker->fetch('src', self::OTHER_TILE, static fn () => new UpstreamFetchResult(null, 404, false));
        self::assertTrue($breaker->fetch('src', self::FIRST_TILE, self::succeed(...))->isSuccess());
    }

    public function testOpenCircuitSkipsTwentyFourWorkersWhenStateLockIsBusy(): void
    {
        $this->breaker(1)->fetch('src', self::FIRST_TILE, self::failTemporarily(...));
        $workers = $this->startWorkers('skip');
        $stateLock = FileLock::acquire(substr($this->statePath(), 0, -5) . '.lock', 0);
        self::assertNotNull($stateLock);
        $this->releaseBarrier($workers);
        usleep(150000);
        $stateLock->release();

        foreach ($workers as $pipes) {
            self::assertSame("skipped\n", fgets($pipes[1]));
        }
    }

    public function testTwentyFourConcurrentFailureCompletionsAreAllCounted(): void
    {
        $this->breaker(100)->fetch('src', self::FIRST_TILE, self::failTemporarily(...));
        $workers = $this->startWorkers('complete');
        $this->releaseBarrier($workers);
        foreach ($workers as $pipes) {
            self::assertSame("fetched\n", fgets($pipes[1]));
        }

        $path = $this->statePath();
        $stateLock = FileLock::acquire(substr($path, 0, -5) . '.lock', 0);
        self::assertNotNull($stateLock);
        $this->releaseBarrier($workers);
        usleep(150000);
        $stateLock->release();

        foreach ($workers as $pipes) {
            self::assertSame("done\n", fgets($pipes[1]));
        }
        $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(25, $state['count']);
    }

    public function testRepairsMalformedStateAndCountsTheNextFailure(): void
    {
        $breaker = $this->breaker(1);
        $breaker->fetch('src', self::FIRST_TILE, self::failTemporarily(...));
        $path = $this->statePath();
        file_put_contents($path, '{');

        $breaker->fetch('src', self::FIRST_TILE, self::failTemporarily(...));
        self::assertNull($breaker->fetch('src', self::OTHER_TILE, self::unexpectedFetch(...)));
        self::assertSame(1, json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['count']);
    }

    private function startWorkers(string $mode): array
    {
        $workerPipes = [];
        for ($worker = 0; $worker < 24; ++$worker) {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/fixtures/circuit-worker.php', $this->root, $mode],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $this->workers[] = [$process, $pipes];
            $workerPipes[] = $pipes;
            stream_set_timeout($pipes[1], 10);
        }
        foreach ($workerPipes as $pipes) {
            self::assertSame("ready\n", fgets($pipes[1]));
        }
        return $workerPipes;
    }

    private function releaseBarrier(array $workerPipes): void
    {
        foreach ($workerPipes as $pipes) {
            fwrite($pipes[0], "go\n");
        }
    }

    private function breaker(int $threshold): UpstreamCircuitBreaker
    {
        return new UpstreamCircuitBreaker($this->root, ['failureThreshold' => $threshold]);
    }

    private function statePath(): string
    {
        $paths = glob($this->root . '/' . UpstreamCircuitBreaker::DIRECTORY . '/*.json');
        self::assertCount(1, $paths);
        return $paths[0];
    }

    private function expireCircuit(): void
    {
        $path = $this->statePath();
        $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $state['openUntil'] = time() - 1;
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
    }

    private static function succeed(): UpstreamFetchResult
    {
        return new UpstreamFetchResult('bytes', 200, false);
    }

    private static function failTemporarily(): UpstreamFetchResult
    {
        return new UpstreamFetchResult(null, 503, false);
    }

    private static function unexpectedFetch(): never
    {
        self::fail('An open circuit must skip the upstream fetch');
    }
}
