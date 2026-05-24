<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use Exception;
use function file_get_contents;
use function filemtime;
use function time;

class Result
{
    public ?int $cacheBrowserTtl = null;
    public ?string $mimeType = null;
    public ?int $cacheMTime = null;
    public ?Exception $failure = null;
    private ?string $data = null;
    private int $cacheStep = 0;

    public function __construct(
        private readonly array         $reqArgs,
        private readonly string        $cachePath,
        private readonly string        $cacheName,
        private readonly MetadataScope $meta
    )
    {
    }

    public function getCacheMTime(): ?int
    {
        if ($this->cacheMTime !== null) {
            return $this->cacheMTime;
        }

        $path = $this->cachePath . '-' . $this->cacheName . '-' . $this->cacheStep;
        $mTime = @filemtime($path);
        if ($mTime === false) {
            $mTime = null;
        }
        $this->cacheMTime = $mTime;
        return $mTime;
    }

    public function getData(): ?string
    {
        if ($this->data !== null) {
            return $this->data;
        }

        return $this->forceLoadFromCache();
    }

    // call `getData` *after* `checkpointCache`

    public function setData($data): void
    {
        $this->data = $data;
    }

    // call `forceLoadFromCache` *after* `checkpointCache`

    public function forceLoadFromCache(): ?string
    {
        $path = $this->cachePath . '-' . $this->cacheName . '-' . ($this->cacheStep - 1);
        $d = @file_get_contents($path);
        if ($d === false) {
            $d = null;
        }
        $this->data = $d;
        return $d;
    }

    // call `getData` *after* `checkpointCache`
    public function checkpointCache(): void
    {
        if ($this->data !== null) {
            $path = $this->cachePath . '-' . $this->cacheName . '-' . $this->cacheStep;

            Utils::writeToFile($path, $this->data);

            $this->cacheMTime = @filemtime($path);
            if ($this->cacheMTime === false) {
                // fallback
                $this->cacheMTime = time();
            }
        }

        ++$this->cacheStep;
    }

    public function isFromCache(): bool
    {
        return $this->data === null;
    }

    public function getReqArgs(): array
    {
        return $this->reqArgs;
    }

    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    public function getMetadata(): MetadataScope
    {
        return $this->meta;
    }
}
