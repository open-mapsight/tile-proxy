<?php

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\MetadataScope;
use OpenMapsight\TileProxy\Processor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CacheServerNameMissingTest extends TestCase
{
    public function testMissingCacheServerNameThrowsExceptionOrIgnores(): void
    {
        $ops = [
            [
                'urls' => ['http://example.com/{z}/{x}/{y}.png'],
                'mimeType' => 'image/png',
                'cacheBrowserTtl' => 3600,
                'cacheServerTtl' => 86400
                // Missing cacheServerName
            ]
        ];

        $reqArgs = ['z' => 0, 'x' => 0, 'y' => 0];
        $cachePath = '/tmp/cache';
        $meta = $this->createMock(MetadataScope::class);

        // We expect it to handle this gracefully or throw an explicit exception instead of a PHP warning/notice.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing `cacheServerName` in operation configuration');

        $result = Processor::run($ops, $reqArgs, $cachePath, $meta);

        $this->assertNotNull($result);
    }
}
