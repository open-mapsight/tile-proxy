<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

class UpstreamFetchResult
{
    public function __construct(
        public readonly ?string $body,
        public readonly ?int    $statusCode,
        public readonly bool    $transportFailed,
        public readonly bool    $contentTypeMismatch = false,
        public readonly ?string $error = null,
    )
    {
    }

    public function isSuccess(): bool
    {
        return !$this->transportFailed
            && !$this->contentTypeMismatch
            && $this->body !== null;
    }
}
