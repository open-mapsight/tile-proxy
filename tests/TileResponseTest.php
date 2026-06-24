<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\TileResponse;
use PHPUnit\Framework\TestCase;

class TileResponseTest extends TestCase
{
    public function testNotFoundResponse(): void
    {
        $response = new TileResponse(null, null, null, null);

        $this->assertTrue($response->isNotFound());
        $this->assertFalse($response->notModified);
    }

    public function testNotModifiedResponse(): void
    {
        $response = new TileResponse(null, 'image/png', 3600, 100, true);

        $this->assertFalse($response->isNotFound());
        $this->assertTrue($response->notModified);
    }
}
