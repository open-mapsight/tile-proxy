<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenMapsight\TileProxy\Base;
use OpenMapsight\TileProxy\Metadata;
use OpenMapsight\TileProxy\MetadataScope;
use OpenMapsight\TileProxy\Processor;
use OpenMapsight\TileProxy\Result;
use OpenMapsight\TileProxy\UpstreamFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UpstreamFailureTest extends TestCase
{
    private const REQ_ARGS = ['z' => '1', 'x' => '0', 'y' => '0', 'prefix' => null];

    private string $tempDir;
    private string $png;
    /** @var array<int, array<string, mixed>> */
    private array $history = [];
    private MockHandler $mock;

    #[DataProvider('temporaryFailures')]
    public function testTemporaryFailureStartsCooldownWithFailureTtl(Response|ConnectException $upstream): void
    {
        $meta = $this->metadata();

        $res = $this->runSrc([$upstream], $meta);

        $this->assertNull($res->getData());
        $this->assertSame(120, $res->cacheBrowserTtl);
        $this->assertNotNull($this->scope($meta)->getLast5xx());
        $this->assertNull($this->scope($meta)->getLast4xx());
        $this->assertSame([], glob($this->tempDir . '/cache-src-*'), 'A failed fetch must not be cached');
    }

    public static function temporaryFailures(): array
    {
        return [
            'maintenance page with HTTP 200' => [new Response(200, ['Content-Type' => 'text/html'], '<html>Wartungsarbeiten</html>')],
            'empty body' => [new Response(200, ['Content-Type' => 'image/png'], '')],
            'undecodable image' => [new Response(200, ['Content-Type' => 'image/png'], 'not an image')],
            'server error' => [new Response(503)],
            'rate limited' => [new Response(429)],
            'transport failure' => [new ConnectException('Connection timed out', new Request('GET', 'https://upstream.test/'))],
        ];
    }

    public function testPermanentClientErrorKeepsLongCooldownAndTtl(): void
    {
        $meta = $this->metadata();

        $res = $this->runSrc([new Response(404)], $meta);

        $this->assertNull($res->getData());
        $this->assertSame(3600, $res->cacheBrowserTtl);
        $this->assertNotNull($this->scope($meta)->getLast4xx());
        $this->assertNull($this->scope($meta)->getLast5xx());
    }

    public function testCooldownSkipsUpstreamAndRecoversAfterwards(): void
    {
        $meta = $this->metadata();
        $this->runSrc([new Response(200, ['Content-Type' => 'text/html'], 'maintenance')], $meta);

        $during = $this->runSrc([], $meta);
        $this->assertCount(0, $this->history, 'No upstream request during the cooldown');
        $this->assertSame(120, $during->cacheBrowserTtl);

        $this->scope($meta)->setLast5xx(time() - 61);
        $after = $this->runSrc([$this->pngResponse()], $meta);

        $this->assertCount(1, $this->history);
        $this->assertSame($this->png, $after->getData());
        $this->assertSame(3600, $after->cacheBrowserTtl);
    }

    public function testFailedRefreshServesStaleCopyWithoutRefreshingIt(): void
    {
        $meta = $this->metadata();
        $this->runSrc([$this->pngResponse()], $meta);
        $cached = glob($this->tempDir . '/cache-src-*');
        $this->assertCount(1, $cached);
        touch($cached[0], time() - 7200);
        clearstatcache();

        $res = $this->runSrc([new ConnectException('timeout', new Request('GET', 'https://upstream.test/'))], $meta);

        $this->assertSame($this->png, $res->getData());
        $this->assertSame(120, $res->cacheBrowserTtl);
        clearstatcache();
        $this->assertLessThanOrEqual(time() - 7200, filemtime($cached[0]), 'The stale copy keeps its age');
    }

    public function testMergeCarriesTheOverlayFailureTtl(): void
    {
        $this->useUpstream([new Response(200, ['Content-Type' => 'text/html'], 'maintenance')]);
        $ops = [
            $this->fileSrc('base'),
            ['op' => 'merge', 'ops' => [$this->httpSrc('overlay')], 'cacheServerName' => 'merged'],
        ];

        $res = Processor::run($ops, self::REQ_ARGS, $this->tempDir . '/cache', $this->metadata());

        $this->assertNull($res->failure);
        $this->assertNotNull($res->getData());
        $this->assertSame(120, $res->cacheBrowserTtl);
    }

    public function testMergeExceptionLowersTtlAndKeepsBaseLayer(): void
    {
        $broken = $this->httpSrc('overlay');
        unset($broken['urls']);
        $ops = [
            $this->fileSrc('base'),
            ['op' => 'merge', 'ops' => [$broken], 'cacheServerName' => 'merged', 'cacheBrowserTtlFail' => 90],
        ];

        $res = Processor::run($ops, self::REQ_ARGS, $this->tempDir . '/cache', $this->metadata());

        $this->assertNull($res->failure);
        $this->assertNotNull($res->getData());
        $this->assertSame(90, $res->cacheBrowserTtl);
    }

    public function testCooldownIsPersistedWhenTheRequestFails(): void
    {
        $this->useUpstream([new Response(503)]);
        $savedGet = $_GET;
        $_GET = ['z' => '1', 'x' => '0', 'y' => '0'];
        try {
            $cfg = [
                'cacheServerPath' => $this->tempDir . '/base-cache',
                'ops' => [$this->httpSrc('src')],
            ];
            try {
                Base::handleTileRequest($cfg, FailingAfterRunProcessor::class);
                $this->fail('An unknown op must fail the request');
            } catch (\Throwable) {
            }
        } finally {
            $_GET = $savedGet;
        }

        $metadataPath = $this->tempDir . '/base-cache/1/0/0-.metadata';
        $this->assertFileExists($metadataPath, 'Metadata must be written');
        $stored = (string) file_get_contents($metadataPath);
        $this->assertStringContainsString('last5xx', $stored);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tile_proxy_failure_' . uniqid();
        mkdir($this->tempDir);
        $img = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($img);
        $this->png = (string) ob_get_clean();
        file_put_contents($this->tempDir . '/tile.png', $this->png);
    }

    protected function tearDown(): void
    {
        UpstreamFetcher::setHttpClient(null);
        $this->removeTree($this->tempDir);
    }

    private function runSrc(array $responses, MetadataScope $meta): Result
    {
        $this->useUpstream($responses);

        $res = Processor::run([$this->httpSrc('src')], self::REQ_ARGS, $this->tempDir . '/cache', $meta);
        // Base::handleTileRequest() writes the final checkpoint the same way.
        $res->checkpointCache();

        return $res;
    }

    private function useUpstream(array $responses): void
    {
        $this->history = [];
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        UpstreamFetcher::setHttpClient(new Client(['handler' => $stack, 'http_errors' => false]));
    }

    private function httpSrc(string $name): array
    {
        return [
            'cacheServerName' => $name,
            'urls' => ['https://upstream.test/{z}/{x}/{y}.png'],
            'mimeType' => 'image/png',
            'cacheBrowserTtl' => 3600,
            'cacheBrowserTtlFail' => 120,
            'cacheServerTtl' => 3600,
            'cacheServer5xxTtl' => 60,
        ];
    }

    private function fileSrc(string $name): array
    {
        return [
            'cacheServerName' => $name,
            'urls' => ['file://' . $this->tempDir . '/tile.png'],
            'mimeType' => 'image/png',
            'cacheBrowserTtl' => 3600,
            'cacheServerTtl' => 86400,
        ];
    }

    private function pngResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'image/png'], $this->png);
    }

    private function metadata(): MetadataScope
    {
        return new MetadataScope(new Metadata($this->tempDir . '/meta.json'), 'test');
    }

    private function scope(MetadataScope $meta): MetadataScope
    {
        return new MetadataScope($meta, 'src');
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}

/** Fails the request after the pipeline ran, as a later op or the cache write could. */
class FailingAfterRunProcessor extends Processor
{
    public static function run(...$args): Result
    {
        parent::run(...$args);

        throw new \RuntimeException('Failure after the source ran');
    }
}
