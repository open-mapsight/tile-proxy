<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Ops;

use OpenMapsight\TileProxy\Result;
use OpenMapsight\TileProxy\Utils;
use RuntimeException;
use Spatie\ImageOptimizer\OptimizerChainFactory;

class ImgOptOp implements OpHandler
{
    public function __invoke(callable $next, array $cfg, Result $res): Result
    {
        if (!$res->isFromCache()) {
            Utils::assertImageMimeType($res->mimeType);

            $tmpDir = sys_get_temp_dir();
            $tmpPath = @tempnam($tmpDir, 'tile-proxy-imgopt');
            if ($tmpPath === false) {
                throw new RuntimeException('Could not create tmp file for img optimization in: ' . $tmpDir);
            }
            try {
                file_put_contents($tmpPath, $res->getData());
                OptimizerChainFactory::create()->optimize($tmpPath);
                $res->setData(file_get_contents($tmpPath));
            } finally {
                unlink($tmpPath);
            }
        }

        return $next($res);
    }
}
