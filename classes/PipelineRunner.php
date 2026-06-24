<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

interface PipelineRunner
{
    /**
     * @param array<int, array<string, mixed>> $ops
     * @param array<string, string|null>       $reqArgs
     * @param array<string, mixed>             $upstreamHttp
     */
    public static function run(
        array         $ops,
        array         $reqArgs,
        string        $cachePath,
        MetadataScope $meta,
        array         $upstreamHttp = []
    ): Result;
}
