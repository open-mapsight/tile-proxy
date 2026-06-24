<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\DiskCache;
use PHPUnit\Framework\TestCase;

class DiskCacheTest extends TestCase
{
    private string $tempDir;

    public function testReadFreshReturnsNullWhenMissing(): void
    {
        $this->assertNull(DiskCache::readFresh($this->tempDir . '/missing', 60));
    }

    public function testReadFreshReturnsDataWithinTtl(): void
    {
        $path = $this->tempDir . '/fresh.bin';
        DiskCache::write($path, 'cached');

        $this->assertSame('cached', DiskCache::readFresh($path, 60));
    }

    public function testReadFreshReturnsNullWhenExpired(): void
    {
        $path = $this->tempDir . '/expired.bin';
        DiskCache::write($path, 'cached');
        touch($path, time() - 120);

        $this->assertNull(DiskCache::readFresh($path, 60));
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/disk_cache_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }
}
