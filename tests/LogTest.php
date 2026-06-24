<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\Log;
use OpenMapsight\TileProxy\UpstreamFetcher;
use PHPUnit\Framework\TestCase;

class LogTest extends TestCase
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
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
        Log::configureFromConfig(['logUpstreamErrors' => true]);

        $this->assertTrue(true);
    }

    public function testSetLoggerReceivesUpstreamFetchWarnings(): void
    {
        $logger = new class($this->logRecords) {
            /** @param list<array{message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            /** @param array<string, mixed> $context */
            public function warning(string $message, array $context = []): void
            {
                $this->records[] = ['message' => $message, 'context' => $context];
            }
        };

        Log::setLogger($logger);

        $result = UpstreamFetcher::fetch('https://example.com/tile.png', [
            'proxy' => 'not-a-valid-proxy',
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->transportFailed);
        $this->assertNotNull($result->error);
        $this->assertCount(1, $this->logRecords);
        $this->assertSame('Upstream fetch failed', $this->logRecords[0]['message']);
        $this->assertSame('https://example.com/tile.png', $this->logRecords[0]['context']['url']);
        $this->assertNotEmpty($this->logRecords[0]['context']['error']);
    }

    public function testMissingFileFetchIncludesErrorReason(): void
    {
        Log::setLogger(new class($this->logRecords) {
            /** @param list<array{message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            /** @param array<string, mixed> $context */
            public function warning(string $message, array $context = []): void
            {
                $this->records[] = ['message' => $message, 'context' => $context];
            }
        });

        $result = UpstreamFetcher::fetch('file:///tmp/mapsight-tile-proxy-missing-' . uniqid());

        $this->assertFalse($result->isSuccess());
        $this->assertSame('file read failed', $result->error);
        $this->assertSame('Upstream file read failed', $this->logRecords[0]['message']);
    }
}
