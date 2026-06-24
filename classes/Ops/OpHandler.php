<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Ops;

use OpenMapsight\TileProxy\Result;

interface OpHandler
{
    public function __invoke(callable $next, array $cfg, Result $res): Result;
}
