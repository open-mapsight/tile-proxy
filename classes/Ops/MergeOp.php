<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Ops;

use Exception;
use OpenMapsight\TileProxy\PipelineRunner;
use OpenMapsight\TileProxy\Result;
use OpenMapsight\TileProxy\Utils;

class MergeOp implements OpHandler
{
    /**
     * @param class-string<PipelineRunner> $pipelineRunner
     * @param array<string, mixed>         $upstreamHttp
     */
    public function __construct(
        private readonly string $pipelineRunner,
        private readonly array  $upstreamHttp = [],
    )
    {
    }

    /**
     * @throws Exception
     */
    public function __invoke(callable $next, array $cfg, Result $res): Result
    {
        $res->checkpointCache();
        Utils::assertImageMimeType($res->mimeType);

        $subRes = ($this->pipelineRunner)::run(
            $cfg['ops'],
            $res->getReqArgs(),
            $res->getCachePath(),
            $res->getMetadata(),
            $this->upstreamHttp
        );

        if ($subRes->failure !== null) {
            return $next($res);
        }

        $subRes->checkpointCache();

        Utils::assertImageMimeType($subRes->mimeType);

        if ((!$res->isFromCache() || !$subRes->isFromCache()) && $res->getData() !== null && $subRes->getData() !== null) {
            $img = Utils::bytesToImage($res->getData());
            $subImg = Utils::bytesToImage($subRes->getData());
            imagecopy(
                $img,
                $subImg,
                0, 0, 0, 0,
                imagesx($subImg),
                imagesy($subImg)
            );
            $res->setData(Utils::imageToBytes($res->mimeType, $img));
        }

        $res->cacheBrowserTtl = min($res->cacheBrowserTtl, $subRes->cacheBrowserTtl);

        return $next($res);
    }
}
