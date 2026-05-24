<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\Metadata;
use OpenMapsight\TileProxy\MetadataScope;
use PHPUnit\Framework\TestCase;

class MetadataTest extends TestCase
{
    private string $tempFile;

    public function testMetadataReadWrite(): void
    {
        $meta = new Metadata($this->tempFile);
        $meta->foo = 'bar';
        $this->assertEquals('bar', $meta->foo);

        Metadata::save($meta);
        $this->assertFileExists($this->tempFile);

        $json = json_decode(file_get_contents($this->tempFile), true);
        $this->assertEquals(['foo' => 'bar'], $json);

        $meta2 = new Metadata($this->tempFile);
        $this->assertEquals('bar', $meta2->foo);
    }

    public function testMetadataScope(): void
    {
        $meta = new Metadata($this->tempFile);
        $scope = new MetadataScope($meta, 'prefix');

        $scope->test = 'value';
        $this->assertEquals('value', $scope->test);
        $this->assertEquals('value', $meta->{'prefix|test'});

        Metadata::save($meta);

        $json = json_decode(file_get_contents($this->tempFile), true);
        $this->assertEquals(['prefix|test' => 'value'], $json);
    }

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'metadata_test');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }
}
