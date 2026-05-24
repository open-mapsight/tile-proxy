<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\Metadata;
use OpenMapsight\TileProxy\MetadataScope;
use OpenMapsight\TileProxy\Result;
use PHPUnit\Framework\TestCase;

class ResultTest extends TestCase
{
    private string $tempCachePath;

    public function testResultState(): void
    {
        $reqArgs = ['z' => 1, 'x' => 0, 'y' => 0, 'prefix' => 'test'];
        $meta = new MetadataScope(new Metadata(""), 'testServer');
        $result = new Result($reqArgs, $this->tempCachePath, 'testServer', $meta);

        $this->assertEquals($reqArgs, $result->getReqArgs());
        $this->assertEquals($this->tempCachePath, $result->getCachePath());
        $this->assertTrue($result->isFromCache());

        $result->setData('some data');
        $this->assertFalse($result->isFromCache());
        $this->assertEquals('some data', $result->getData());
    }

    public function testCheckpointCache(): void
    {
        $reqArgs = ['z' => 1, 'x' => 0, 'y' => 0, 'prefix' => 'test'];
        $meta = new MetadataScope(new Metadata(""), 'testServer');
        $result = new Result($reqArgs, $this->tempCachePath, 'testServer', $meta);

        $result->setData('initial data');
        $result->checkpointCache();

        // Check if file was created. Result class uses: $this->cachePath . '-' . $this->cacheName . '-' . $this->cacheStep
        // checkpointCache increments cacheStep AFTER writing. So it was written to step 0.
        $expectedPath = $this->tempCachePath . '-testServer-0';
        $this->assertFileExists($expectedPath);
        $this->assertEquals('initial data', file_get_contents($expectedPath));

        // Now test loading from cache in another Result object
        $result2 = new Result($reqArgs, $this->tempCachePath, 'testServer', $meta);
        // We need to move to step 1 to force load from step 0
        // checkpointCache() increments step.
        // We can just call it once (it won't write if data is null, but it increments step)
        $result2->checkpointCache();

        $this->assertEquals('initial data', $result2->forceLoadFromCache());
    }

    protected function setUp(): void
    {
        $this->tempCachePath = __DIR__ . '/../temp_cache_' . uniqid();
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempCachePath . '*');
        foreach ($files as $file) {
            unlink($file);
        }
    }
}
