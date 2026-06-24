<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

class TileResponse
{
    public function __construct(
        public readonly ?string $body,
        public readonly ?string $mimeType,
        public readonly ?int    $cacheBrowserTtl,
        public readonly ?int    $cacheMTime,
        public readonly bool    $notModified = false,
    )
    {
    }

    public function isNotFound(): bool
    {
        return $this->mimeType === null;
    }
}
