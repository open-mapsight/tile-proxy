<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\FileLock;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ResilienceHttpTest extends TestCase
{
    private string $tempDir;
    private mixed $server = null;
    private array $serverPipes = [];
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tile_proxy_http_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir);
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:0', '-t', $this->tempDir, __DIR__ . '/fixtures/resilience-router.php'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $this->serverPipes,
        );
        self::assertIsResource($this->server);
        stream_set_timeout($this->serverPipes[2], 5);
        $startup = fgets($this->serverPipes[2]);
        self::assertIsString($startup);
        self::assertMatchesRegularExpression('#http://127\.0\.0\.1:\d+#', $startup);
        preg_match('#http://127\.0\.0\.1:\d+#', $startup, $matches);
        $this->baseUrl = $matches[0];
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            foreach ($this->serverPipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->server);
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tempDir);
    }

    public function testStaleStyleAndTileJsonCapMissingTileHeadersIncludingSidecarHits(): void
    {
        foreach (['direct' => 'public, max-age=7', 'indirect' => 'public, max-age=7', 'zero' => 'no-store'] as $style => $cacheControl) {
            for ($request = 0; $request < 2; ++$request) {
                $headers = $this->request("/map-assets/tiles/$style/source/0/1/0/0.pbf", 404);
                self::assertSame($cacheControl, $headers['cache-control'] ?? null);
                self::assertArrayNotHasKey('last-modified', $headers);
                if ($style === 'zero') {
                    self::assertArrayNotHasKey('expires', $headers);
                } else {
                    self::assertArrayHasKey('expires', $headers);
                }
            }
        }
    }

    public function testBreakerOnlyRasterMissesAreNotStoredAndLegacySenderRemainsUnchanged(): void
    {
        $legacy = $this->request('/legacy', 404);
        self::assertArrayNotHasKey('cache-control', $legacy);
        self::assertArrayNotHasKey('expires', $legacy);

        foreach ([0, 1, 0] as $y) {
            $headers = $this->request("/raster?z=1&x=0&y=$y", 404);
            self::assertSame('no-store', $headers['cache-control'] ?? null);
            self::assertArrayNotHasKey('expires', $headers);
        }
    }

    public function testColdTemporaryMapboxResponseIncludesKnownCooldown(): void
    {
        for ($request = 0; $request < 2; ++$request) {
            $headers = $this->request('/map-assets/styles/cold.json', 503);
            self::assertSame('no-store', $headers['cache-control'] ?? null);
            self::assertArrayHasKey('retry-after', $headers);
            self::assertGreaterThan(0, (int)$headers['retry-after']);
            self::assertLessThanOrEqual(300, (int)$headers['retry-after']);
        }
    }

    public function testStaleTileKeepsItsExplicitZeroTtlWhenRefreshLockIsBusy(): void
    {
        $style = 'collision';
        $upstream = 'https://tiles.test/collision/tile/1/0/0.pbf';
        $root = $this->tempDir . '/cache/mapbox-style-proxy';
        $tileCache = $root . '/' . $style . '/tile/' . sha1($upstream);
        mkdir(dirname($tileCache), 0777, true);
        file_put_contents($tileCache, 'stale vector bytes');
        touch($tileCache, time() - 700000);
        $path = '/map-assets/tiles/collision/source/0/1/0/0.pbf';
        self::assertSame('public, max-age=0', $this->request($path, 200)['cache-control'] ?? null);
        self::assertFileExists($tileCache . '.failure.json');

        $hash = hash('sha256', serialize([$style, 'tile', $upstream]));
        $lock = FileLock::acquire($root . '/.locks/' . substr($hash, 0, 2) . '.lock', 0);
        self::assertNotNull($lock);
        try {
            self::assertSame('public, max-age=0', $this->request($path, 200)['cache-control'] ?? null);
            unlink($tileCache . '.failure.json');
            self::assertSame('public, max-age=0', $this->request($path, 200)['cache-control'] ?? null);
            unlink($tileCache);
            self::assertSame('no-store', $this->request($path, 503)['cache-control'] ?? null);
        } finally {
            $lock->release();
        }
    }

    public function testLargestConfigured404TtlProducesARepresentableHttpDate(): void
    {
        $headers = $this->request('/huge-404?z=1&x=0&y=0', 404);
        self::assertMatchesRegularExpression('/^public, max-age=[1-9][0-9]*$/', $headers['cache-control']);
        self::assertMatchesRegularExpression('/ [0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} GMT$/', $headers['expires']);
        self::assertGreaterThan(time(), strtotime($headers['expires']));
    }

    private function request(string $path, int $status): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        $stream = fopen($this->baseUrl . $path, 'rb', false, $context);
        self::assertIsResource($stream);
        $lines = stream_get_meta_data($stream)['wrapper_data'];
        fclose($stream);
        self::assertMatchesRegularExpression('#^HTTP/\d\.\d ' . $status . ' #', array_shift($lines));
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)] = trim($value);
        }

        return $headers;
    }
}
