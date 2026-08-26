<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Temporary CI matrix smoke test: the pipe operator is a PHP 8.5 language feature.
 * This file should parse and pass only on 8.5; older matrix jobs are expected to fail.
 */
class Php85SyntaxDemoTest extends TestCase
{
    public function testPipeOperatorUppercasesALabel(): void
    {
        $label = 'tile-proxy'
            |> strtoupper(...)
            |> trim(...);

        $this->assertSame('TILE-PROXY', $label);
    }
}
