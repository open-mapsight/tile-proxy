<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\UpstreamFetcher;
use PHPUnit\Framework\TestCase;

class UpstreamFetcherTest extends TestCase
{
    private string $tempDir;
    private string $assetFile;

    public function testFetchFileUrl(): void
    {
        $result = UpstreamFetcher::fetch('file://' . $this->assetFile);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('tile bytes', $result->body);
        $this->assertSame(200, $result->statusCode);
        $this->assertFalse($result->transportFailed);
    }

    public function testGuzzleOptionsFromUpstreamHttpConfig(): void
    {
        $options = UpstreamFetcher::guzzleOptionsFromHttpConfig([
            'proxy' => 'tcp://proxy.example.test:8080',
            'timeout' => 12,
            'connect_timeout' => 3,
            'allow_redirects' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'tile-proxy-test',
            ],
        ]);

        $this->assertSame('tcp://proxy.example.test:8080', $options['proxy']);
        $this->assertSame(12.0, $options['timeout']);
        $this->assertSame(3.0, $options['connect_timeout']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('application/json', $options['headers']['Accept']);
        $this->assertSame('tile-proxy-test', $options['headers']['User-Agent']);
    }

    public function testFetchMissingFileFails(): void
    {
        $result = UpstreamFetcher::fetch('file://' . $this->tempDir . '/missing.png');

        $this->assertFalse($result->isSuccess());
    }

    public function testMatchesContentTypeIgnoresParameters(): void
    {
        $this->assertTrue(UpstreamFetcher::matchesContentType('image/png; charset=binary', 'image/png'));
        $this->assertFalse(UpstreamFetcher::matchesContentType('application/json', 'image/png'));
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/upstream_fetcher_test_' . uniqid();
        mkdir($this->tempDir);
        $this->assetFile = $this->tempDir . '/tile.png';
        file_put_contents($this->assetFile, 'tile bytes');
    }

    protected function tearDown(): void
    {
        if (is_file($this->assetFile)) {
            unlink($this->assetFile);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }
}
