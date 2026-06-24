<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Ops;

use OpenMapsight\TileProxy\OpColorFilterUtils;
use OpenMapsight\TileProxy\Result;
use OpenMapsight\TileProxy\Utils;
use RuntimeException;

class ColorFilterOp implements OpHandler
{
    public function __invoke(callable $next, array $cfg, Result $res): Result
    {
        if (!$res->isFromCache()) {
            Utils::assertImageMimeType($res->mimeType);

            $data = (static function () use ($cfg, $res) {
                return match ($cfg['filter']) {
                    'reducedSaturation' => OpColorFilterUtils::reducedSaturation($res->getData()),
                    'muted' => OpColorFilterUtils::muted($res->getData()),
                    'culture' => OpColorFilterUtils::manipulatePixelsHsl(
                        $res->getData(),
                        static function ($h, $s, $l) {
                            return [$h, $s * 0.1, min(1, $l * 1.1)];
                        }
                    ),
                    default => throw new RuntimeException('Unsupported color filter: "' . $cfg['filter'] . '"'),
                };
            })();

            $res->setData($data);
        }

        $res->mimeType = 'image/png';

        return $next($res);
    }
}
