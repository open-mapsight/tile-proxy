<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\HttpResponse;
use PHPUnit\Framework\TestCase;

class HttpResponseTest extends TestCase
{
    public function testNotFoundResponse(): void
    {
        $response = new HttpResponse(null, null, null, null);

        $this->assertTrue($response->isNotFound());
        $this->assertFalse($response->notModified);
    }

    public function testNotModifiedResponse(): void
    {
        $response = new HttpResponse(null, 'image/png', 3600, 100, true);

        $this->assertFalse($response->isNotFound());
        $this->assertTrue($response->notModified);
    }

    public function testSendOutputsBody(): void
    {
        ob_start();

        HttpResponse::send(new HttpResponse('tile bytes', 'image/png', 3600, null));

        $this->assertSame('tile bytes', ob_get_clean());
    }
}
