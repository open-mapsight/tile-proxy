<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Ops;

use OpenMapsight\TileProxy\Result;
use OpenMapsight\TileProxy\Utils;
use RuntimeException;

class EncodeOp implements OpHandler
{
    public function __invoke(callable $next, array $cfg, Result $res): Result
    {
        if (!$res->isFromCache()) {
            $mimeType = $cfg['mimeType'] ?? null;
            if (!is_string($mimeType) || $mimeType === '') {
                throw new RuntimeException('encode requires `mimeType`');
            }

            Utils::assertImageMimeType($mimeType);

            $quality = isset($cfg['quality']) ? (int)$cfg['quality'] : null;
            $img = Utils::bytesToImage($res->getData());
            try {
                $res->setData(Utils::imageToBytes($mimeType, $img, $quality));
                $res->mimeType = $mimeType;
            } finally {
                imagedestroy($img);
            }
        } elseif (isset($cfg['mimeType']) && is_string($cfg['mimeType'])) {
            $res->mimeType = $cfg['mimeType'];
        }

        return $next($res);
    }
}
