<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\HttpResponse;
use OpenMapsight\TileProxy\Log;
use OpenMapsight\TileProxy\UpstreamFetcher;
use OpenMapsight\TileProxy\UserException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LogTest extends TestCase
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logRecords = [];

    protected function setUp(): void
    {
        Log::reset();
        $this->logRecords = [];
    }

    protected function tearDown(): void
    {
        Log::reset();
    }

    public function testConfigureFromConfigEnablesErrorLog(): void
    {
        Log::configureFromConfig(['logErrors' => true]);

        $this->assertTrue(true);
    }

    public function testSetLoggerReceivesUpstreamFetchWarnings(): void
    {
        Log::setLogger($this->createLogger());

        $result = UpstreamFetcher::fetch('https://example.com/tile.png', [
            'proxy' => 'not-a-valid-proxy',
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->transportFailed);
        $this->assertNotNull($result->error);
        $this->assertCount(1, $this->logRecords);
        $this->assertSame('warning', $this->logRecords[0]['level']);
        $this->assertSame('Upstream fetch failed', $this->logRecords[0]['message']);
        $this->assertSame('https://example.com/tile.png', $this->logRecords[0]['context']['url']);
        $this->assertNotEmpty($this->logRecords[0]['context']['error']);
    }

    public function testMissingFileFetchIncludesErrorReason(): void
    {
        Log::setLogger($this->createLogger());

        $result = UpstreamFetcher::fetch('file:///tmp/mapsight-tile-proxy-missing-' . uniqid());

        $this->assertFalse($result->isSuccess());
        $this->assertSame('file read failed', $result->error);
        $this->assertSame('warning', $this->logRecords[0]['level']);
        $this->assertSame('Upstream file read failed', $this->logRecords[0]['message']);
    }

    public function testRequestHandlerFailuresAreLoggedAsErrors(): void
    {
        Log::setLogger($this->createLogger());

        HttpResponse::sendRequest(
            ['logErrors' => true],
            static fn () => throw new RuntimeException('cache write failed')
        );

        $this->assertCount(1, $this->logRecords);
        $this->assertSame('error', $this->logRecords[0]['level']);
        $this->assertSame('Request handler failed', $this->logRecords[0]['message']);
        $this->assertSame(RuntimeException::class, $this->logRecords[0]['context']['exception']);
        $this->assertSame('cache write failed', $this->logRecords[0]['context']['error']);
    }

    public function testUserExceptionsAreNotLogged(): void
    {
        Log::setLogger($this->createLogger());

        HttpResponse::sendRequest(
            ['logErrors' => true],
            static fn () => throw new UserException('Unsupported map asset route')
        );

        $this->assertSame([], $this->logRecords);
    }

    private function createLogger(): object
    {
        return new class($this->logRecords) {
            /** @param list<array{level: string, message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            /** @param array<string, mixed> $context */
            public function warning(string $message, array $context = []): void
            {
                $this->records[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
            }

            /** @param array<string, mixed> $context */
            public function error(string $message, array $context = []): void
            {
                $this->records[] = ['level' => 'error', 'message' => $message, 'context' => $context];
            }
        };
    }
}
