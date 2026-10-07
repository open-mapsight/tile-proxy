<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use InvalidArgumentException;
use OpenMapsight\TileProxy\Base;
use OpenMapsight\TileProxy\FileLock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class CacheLockTest extends TestCase
{
    private string $tempDir;
    private array $savedGet;
    private array $workers = [];

    public function testTileRequestCanWaitBeyondTheOldLockBudget(): void
    {
        $worker = $this->worker('hold', $this->metadataPath(), 0, 3100000);
        $this->assertSame("LOCKED\n", fgets($worker['pipes'][1]));

        $response = Base::handleTileRequest($this->config(4));

        $this->assertSame('tile', $response->body);
        $this->assertSame('tile', file_get_contents($this->tempDir . '/cache/0/0/0-source-0'));
    }

    public function testConfiguredTimeoutIsBoundedAndClosesItsHandle(): void
    {
        $lock = FileLock::acquire($this->metadataPath(), 0);
        $this->assertNotNull($lock);
        $start = hrtime(true);
        try {
            Base::handleTileRequest($this->config(0.03));
            $this->fail('A competing request must time out');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Can not lock', $error->getMessage());
            $this->assertLessThan(1, (hrtime(true) - $start) / 1e9);
        } finally {
            $lock->release();
        }

        $nextLock = FileLock::acquire($this->metadataPath(), 0);
        $this->assertNotNull($nextLock);
        $nextLock->release();
    }

    public function testPipelineFailureReleasesTheLock(): void
    {
        $cfg = $this->config(0);
        $cfg['ops'][] = ['op' => 'unknown'];
        try {
            Base::handleTileRequest($cfg);
            $this->fail('An unknown operation must fail');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('No op handler', $error->getMessage());
        }

        $lock = FileLock::acquire($this->metadataPath(), 0);
        $this->assertNotNull($lock);
        $lock->release();
    }

    public function testWaiterCannotUseAnUnlinkedMetadataInode(): void
    {
        $worker = $this->worker('wait', $this->metadataPath(), 0.3);
        $this->assertSame("READY\n", fgets($worker['pipes'][1]));
        $oldLock = FileLock::acquire($this->metadataPath(), 0);
        $replacement = FileLock::acquire($this->tempDir . '/replacement', 0);
        $this->assertNotNull($oldLock);
        $this->assertNotNull($replacement);
        fwrite($worker['pipes'][0], "GO\n");
        usleep(100000);

        // Atomically replace the locked inode, as cleanup followed by a new request can do.
        rename($this->tempDir . '/replacement', $this->metadataPath());
        $oldLock->release();
        $this->assertSame("TIMEOUT\n", fgets($worker['pipes'][1]));
        // Wait for process exit before checking that a new request can acquire the lock.
        $this->assertSame('', stream_get_contents($worker['pipes'][1]));
        $replacement->release();

        $this->assertNotNull(FileLock::acquire($this->metadataPath(), 0));
    }

    public function testZeroTimeoutDoesNotWaitAndMissingReadOnlyLockIsNotCreated(): void
    {
        $this->assertNull(FileLock::acquire($this->tempDir . '/missing', 0, false));
        $this->assertFileDoesNotExist($this->tempDir . '/missing');
        $lock = FileLock::acquire($this->metadataPath(), 0);
        $this->assertNotNull($lock);
        $this->assertNull(FileLock::acquire($this->metadataPath(), 0));
        $lock->release();
    }

    public function testRetriesWhenDirectoryIsPrunedDuringCreation(): void
    {
        $path = $this->metadataPath();
        $directory = dirname($path);
        mkdir($directory, 0777, true);
        $removed = false;
        set_error_handler(static function (int $severity, string $message) use ($directory, &$removed): bool {
            if (!$removed && str_contains($message, 'mkdir(): File exists')) {
                // Remove the empty directory between mkdir() failing and mkdirp() checking is_dir().
                rmdir($directory);
                clearstatcache(true, $directory);
                $removed = true;
                return true;
            }

            return false;
        });

        try {
            $lock = FileLock::acquire($path, 0.2);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($removed);
        $this->assertNotNull($lock);
        $lock->release();
        $this->assertFileExists($path);
    }

    public function testDirectoryCreationFailureIsReportedWithinTheLockBudget(): void
    {
        $parentFile = $this->tempDir . '/not-a-directory';
        file_put_contents($parentFile, 'blocked');
        $timeout = 0.03;
        $start = hrtime(true);

        try {
            FileLock::acquire($parentFile . '/metadata', $timeout);
            $this->fail('A file cannot be used as the metadata directory');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString($parentFile, $error->getMessage());
            $elapsed = (hrtime(true) - $start) / 1e9;
            $this->assertGreaterThanOrEqual($timeout, $elapsed);
            $this->assertLessThan(1, $elapsed);
        }
    }

    #[DataProvider('invalidTimeouts')]
    public function testInvalidTimeoutIsRejected(float $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        FileLock::acquire($this->metadataPath(), $timeout);
    }

    public static function invalidTimeouts(): array
    {
        return [[-1.0], [INF], [NAN]];
    }

    public function testCacheHitUpdatesMetadataAccessTimeWithoutRefreshingTile(): void
    {
        Base::handleTileRequest($this->config(0));
        $tilePath = $this->tempDir . '/cache/0/0/0-source-0';
        $oldTime = time() - 60;
        touch($tilePath, $oldTime);
        touch($this->metadataPath(), $oldTime);
        unlink($this->tempDir . '/tile');
        clearstatcache();

        $this->assertSame('tile', Base::handleTileRequest($this->config(0))->body);
        clearstatcache();
        $this->assertSame($oldTime, filemtime($tilePath));
        $this->assertGreaterThan($oldTime, filemtime($this->metadataPath()));
    }

    private function config(float $timeout): array
    {
        return [
            'cacheServerPath' => $this->tempDir . '/cache',
            'cacheLockTimeout' => $timeout,
            'ops' => [[
                'cacheServerName' => 'source',
                'urls' => ['file://' . $this->tempDir . '/tile'],
                'mimeType' => 'image/png',
                'cacheBrowserTtl' => 60,
                'cacheServerTtl' => 3600,
            ]],
        ];
    }

    private function metadataPath(): string
    {
        return $this->tempDir . '/cache/0/0/0-.metadata';
    }

    private function worker(string $mode, string $path, float $timeout, int $holdMicroseconds = 0): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/cache-lock-worker.php', $mode, $path, (string)$timeout, (string)$holdMicroseconds],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        stream_set_timeout($pipes[1], 10);
        $worker = ['process' => $process, 'pipes' => $pipes];
        $this->workers[] = $worker;
        return $worker;
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tile_proxy_lock_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir);
        file_put_contents($this->tempDir . '/tile', 'tile');
        $this->savedGet = $_GET;
        $_GET = ['z' => '0', 'x' => '0', 'y' => '0'];
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            fclose($worker['pipes'][0]);
            $errors = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $status = proc_close($worker['process']);
            $this->assertSame(0, $status, $errors);
        }
        $_GET = $this->savedGet;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tempDir);
    }
}
