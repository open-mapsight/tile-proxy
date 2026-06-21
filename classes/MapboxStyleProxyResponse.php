<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

class MapboxStyleProxyResponse
{
    public function __construct(
        public readonly string $data,
        public readonly string $mimeType,
        public readonly ?int $cacheBrowserTtl = null
    ) {
    }
}
