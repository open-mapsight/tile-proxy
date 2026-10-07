<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use InvalidArgumentException;
use OpenMapsight\TileProxy\Base;
use OpenMapsight\TileProxy\CachePruner;
use OpenMapsight\TileProxy\FileLock;
use OpenMapsight\TileProxy\MapboxStyleProxy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use RuntimeException;

class CachePrunerTest extends TestCase
{
    private string $tempDir;

    #[DataProvider('cacheNamespaces')]
    public function testRemovesOldTilesMetadataAndEmptyDirectoriesIncludingPrefixes(string $namespace): void
    {
        $this->write('dark/12/2048/1024-' . $namespace . '-0', 120);
        $this->write('dark/12/2048/1024-overlay-0', 120);
        $this->write('dark/12/2048/1024-' . $namespace . '-1', 120);
        $this->write('dark/12/2048/1024-.metadata', 120);

        $this->assertSame(4, CachePruner::prune($this->tempDir, 60));
        $this->assertDirectoryDoesNotExist($this->tempDir . '/dark');
        $this->assertDirectoryExists($this->tempDir);
        $this->assertSame(0, CachePruner::prune($this->tempDir, 60));
    }

    #[DataProvider('cacheNamespaces')]
    public function testRemovesObsoleteNamespacesButKeepsFreshTileAndItsMetadata(string $namespace): void
    {
        $old = $this->write('1/0/0-old-url-hash-0', 120);
        $fresh = $this->write('1/0/0-' . $namespace . '-0');
        $metadata = $this->write('1/0/0-.metadata', 120);

        $this->assertSame(1, CachePruner::prune($this->tempDir, 60));
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
        $this->assertFileExists($metadata);
    }

    public static function cacheNamespaces(): array
    {
        return [
            'named namespace' => ['current-url-hash'],
            'empty namespace' => [''],
        ];
    }

    public function testSkipsTilesLockedByARequestThenPrunesAfterRelease(): void
    {
        $tile = $this->write('1/0/0-source-0', 120);
        $metadata = $this->write('1/0/0-.metadata', 120);
        $lock = FileLock::acquire($metadata, 0);
        $this->assertNotNull($lock);

        $this->assertSame(0, CachePruner::prune($this->tempDir, 60));
        $this->assertFileExists($tile);
        $lock->release();
        $this->assertSame(2, CachePruner::prune($this->tempDir, 60));
    }

    public function testKeepsRecentlyRequestedMetadataEvenWhenAllTilesAreOld(): void
    {
        $this->write('1/0/0-source-0', 120);
        $metadata = $this->write('1/0/0-.metadata');
        $this->write('1/0/1-.metadata', 120);

        $this->assertSame(2, CachePruner::prune($this->tempDir, 60));
        $this->assertFileExists($metadata);
    }

    #[DataProvider('staleScans')]
    public function testKeepsMetadataForCheckpointWrittenAfterScanByAFailedRequest(
        bool $scanHadTile, string $checkpointNamespace
    ): void
    {
        $metadata = $this->write('cache/1/0/0-.metadata', 120);
        $scannedTiles = $scanHadTile ? [$this->write('cache/1/0/0-old-source-0', 120)] : [];
        $source = $this->write('source');
        $metadataMtime = filemtime($metadata);
        $savedGet = $_GET;
        $_GET = ['z' => '1', 'x' => '0', 'y' => '0'];

        try {
            Base::handleTileRequest([
                'cacheServerPath' => $this->tempDir . '/cache',
                'cacheLockTimeout' => 0,
                'ops' => [
                    [
                        'cacheServerName' => $checkpointNamespace,
                        'urls' => ['file://' . $source],
                        'mimeType' => 'image/png',
                        'cacheBrowserTtl' => 60,
                        'cacheServerTtl' => 3600,
                    ],
                    // Merge checkpoints the source before this invalid subpipeline fails.
                    ['op' => 'merge', 'ops' => [[]]],
                ],
            ]);
            $this->fail('The merge subpipeline must fail after checkpointing the source');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Missing `cacheServerName`', $error->getMessage());
        } finally {
            $_GET = $savedGet;
        }

        $checkpoint = $this->tempDir . '/cache/1/0/0-' . $checkpointNamespace . '-0';
        $this->assertFileExists($checkpoint);
        clearstatcache(true, $metadata);
        $this->assertSame($metadataMtime, filemtime($metadata));

        // Resume pruning with the pre-request scan to reproduce the interleaving without timing races.
        $pruneTile = new ReflectionMethod(CachePruner::class, 'pruneTile');
        $this->assertSame($scanHadTile ? 1 : 0, $pruneTile->invoke(null, $metadata, $scannedTiles, time() - 60));
        $this->assertFileExists($checkpoint);
        $this->assertFileExists($metadata);

        // Keeping metadata lets a later retention run remove the checkpoint once it ages out.
        touch($checkpoint, time() - 120);
        $this->assertSame(2, CachePruner::prune($this->tempDir, 60));
        $this->assertFileDoesNotExist($checkpoint);
        $this->assertFileDoesNotExist($metadata);
    }

    public static function staleScans(): array
    {
        return [
            'metadata only' => [false, 'new-source'],
            'obsolete namespace' => [true, 'new-source'],
            'metadata only with empty checkpoint namespace' => [false, ''],
            'obsolete namespace with empty checkpoint namespace' => [true, ''],
        ];
    }

    #[DataProvider('cacheRootSuffixes')]
    public function testSharedCachePruningPreservesAMapboxAssetWriteInProgress(string $rootSuffix): void
    {
        $this->write('1/0/0-source-0', 120);
        $this->write('1/0/0-.metadata', 120);
        $style = '{"version":8,"sources":{},"layers":[]}';
        $upstreamStyle = $this->write('upstream-style.json');
        file_put_contents($upstreamStyle, $style);
        $mapboxDirectory = $this->tempDir . '/mapbox-style-proxy/example/style';
        mkdir($mapboxDirectory, 0777, true);
        $cacheRoot = $this->tempDir . $rootSuffix;
        $pruned = false;
        $deleted = null;

        set_error_handler(static function (int $severity, string $message) use ($cacheRoot, &$pruned, &$deleted): bool {
            if (!$pruned && str_contains($message, 'mkdir(): File exists')) {
                // Pause the asset writer in mkdirp(), while its existing directory is still empty.
                $pruned = true;
                $deleted = CachePruner::prune($cacheRoot, 60);
                return true;
            }

            return false;
        });

        try {
            $response = MapboxStyleProxy::handleRequest([
                'cacheServerPath' => $cacheRoot,
                'styles' => ['example' => [
                    'upstreamStyleUrl' => 'file://' . $upstreamStyle,
                    'allowedSchemes' => ['file'],
                    'allowedPathPrefixes' => [$this->tempDir . '/'],
                ]],
            ], '/styles/example.json');
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($pruned);
        $this->assertSame(2, $deleted);
        $this->assertSame(8, json_decode($response->body, true)['version']);
        $this->assertDirectoryExists($mapboxDirectory);
        $this->assertSame($style, file_get_contents($mapboxDirectory . '/' . sha1('file://' . $upstreamStyle)));
    }

    #[DataProvider('cacheRootSuffixes')]
    public function testIgnoresFilesAndEmptyDirectoriesInReservedMapboxCacheTree(string $rootSuffix): void
    {
        $asset = $this->write('mapbox-style-proxy/example/style/abc', 120);
        $tile = $this->write('mapbox-style-proxy/example/tile/1-source-0', 120);
        $metadata = $this->write('mapbox-style-proxy/example/tile/1-.metadata', 120);
        $emptyDirectory = $this->tempDir . '/mapbox-style-proxy/example/glyph';
        mkdir($emptyDirectory);

        $this->assertSame(0, CachePruner::prune($this->tempDir . $rootSuffix, 60));
        foreach ([$asset, $tile, $metadata] as $path) {
            $this->assertFileExists($path);
        }
        $this->assertDirectoryExists($emptyDirectory);
    }

    public static function cacheRootSuffixes(): array
    {
        return [
            'no trailing slash' => [''],
            'one trailing slash' => ['/'],
            'two trailing slashes' => ['//'],
            'three trailing slashes' => ['///'],
        ];
    }

    public function testIgnoresSymlinksUnrelatedFilesAndOtherTileCoordinates(): void
    {
        $this->write('1/0/1-.metadata', 120);
        $this->write('1/0/1-source-0', 120);
        $other = $this->write('1/0/10-source-0');
        $this->write('1/0/10-.metadata');
        $unrelated = $this->write('1/0/notes.txt', 120);
        $asset = $this->write('map-assets/abc.json', 120);
        $target = $this->write('target', 120);
        $this->write('1/0/2-.metadata', 120);
        symlink($target, $this->tempDir . '/1/0/2-source-0');
        symlink($this->tempDir . '/1', $this->tempDir . '/linked');

        $this->assertSame(3, CachePruner::prune($this->tempDir, 60));
        foreach ([$other, $unrelated, $asset, $target, $this->tempDir . '/1/0/2-source-0'] as $path) {
            $this->assertFileExists($path);
        }
        $this->assertTrue(is_link($this->tempDir . '/linked'));
    }

    public function testMissingCacheIsANoOp(): void
    {
        $this->assertSame(0, CachePruner::prune($this->tempDir . '/missing', 60));
        $this->assertDirectoryDoesNotExist($this->tempDir . '/missing');
    }

    public function testDirectoryRemovedByAnotherProcessIsANoOpDespiteCachedStat(): void
    {
        if (!defined('SIGSTOP') || !defined('SIGCONT')) {
            $this->markTestSkipped('Process signals are required to preserve the parent stat cache');
        }

        $directory = $this->tempDir . '/removed';
        mkdir($directory);
        $pruneDirectory = new ReflectionMethod(CachePruner::class, 'pruneDirectory');

        // A subprocess removes the directory without clearing this process's positive stat cache.
        $process = proc_open(
            [PHP_BINARY, '-r', 'fgets(STDIN); exit(rmdir($argv[1]) ? 0 : 1);', $directory],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);

        try {
            proc_terminate($process, SIGSTOP);
            $deadline = hrtime(true) + 2e9;
            do {
                $status = proc_get_status($process);
                if ($status['stopped'] || !$status['running'] || hrtime(true) >= $deadline) {
                    break;
                }
                usleep(1000);
            } while (true);
            $this->assertTrue($status['stopped']);
            fwrite($pipes[0], "GO\n");
            $this->assertTrue(is_dir($directory));

            // Signals and process-status polling avoid pipe I/O, which clears PHP's stat cache.
            proc_terminate($process, SIGCONT);
            $deadline = hrtime(true) + 2e9;
            do {
                $status = proc_get_status($process);
                if (!$status['running'] || hrtime(true) >= $deadline) {
                    break;
                }
                usleep(1000);
            } while (true);
            $this->assertTrue(is_dir($directory), 'The parent must retain the stale positive directory stat');

            $this->assertSame(0, $pruneDirectory->invoke(null, $directory, time() - 60));
            $this->assertFalse($status['running']);
            $this->assertSame(0, $status['exitcode']);
            $this->assertDirectoryDoesNotExist($directory);
        } finally {
            proc_terminate($process, SIGCONT);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    public function testNonPositiveRetentionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CachePruner::prune($this->tempDir, 0);
    }

    private function write(string $relativePath, int $age = 0): string
    {
        $path = $this->tempDir . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, '{}');
        touch($path, time() - $age);
        return $path;
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tile_proxy_pruner_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tempDir);
    }
}
