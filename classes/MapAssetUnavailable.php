<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use RuntimeException;

/** @internal */
final class MapAssetUnavailable extends RuntimeException
{
    public function __construct(
        public readonly ?int $notFoundTtl = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct();
    }
}
