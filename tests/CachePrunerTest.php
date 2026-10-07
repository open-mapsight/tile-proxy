<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use InvalidArgumentException;
use OpenMapsight\TileProxy\CachePruner;
use OpenMapsight\TileProxy\FileLock;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class CachePrunerTest extends TestCase
{
    private string $tempDir;

    public function testRemovesOldTilesMetadataAndEmptyDirectoriesIncludingPrefixes(): void
    {
        $this->write('dark/12/2048/1024-source-0', 120);
        $this->write('dark/12/2048/1024-overlay-0', 120);
        $this->write('dark/12/2048/1024-source-1', 120);
        $this->write('dark/12/2048/1024-.metadata', 120);

        $this->assertSame(4, CachePruner::prune($this->tempDir, 60));
        $this->assertDirectoryDoesNotExist($this->tempDir . '/dark');
        $this->assertDirectoryExists($this->tempDir);
        $this->assertSame(0, CachePruner::prune($this->tempDir, 60));
    }

    public function testRemovesObsoleteNamespacesButKeepsFreshTileAndItsMetadata(): void
    {
        $old = $this->write('1/0/0-old-url-hash-0', 120);
        $fresh = $this->write('1/0/0-current-url-hash-0');
        $metadata = $this->write('1/0/0-.metadata', 120);

        $this->assertSame(1, CachePruner::prune($this->tempDir, 60));
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
        $this->assertFileExists($metadata);
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
