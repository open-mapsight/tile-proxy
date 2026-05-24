<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use Exception;
use RuntimeException;
use Spatie\ImageOptimizer\OptimizerChainFactory;

class Processor
{
    protected static function opColorFilter(
        callable $next,
        array    $cfg,
        Result   $res
    ): Result
    {
        if (!$res->isFromCache()) {
            Utils::assertImageMimeType($res->mimeType);

            $data = (static function () use ($cfg, $res) {
                return match ($cfg['filter']) {
                    'reducedSaturation' => OpColorFilterUtils::reducedSaturation($res->getData()),
                    'bremen' => OpColorFilterUtils::muted($res->getData()),
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

    protected static function opImgOpt(
        callable $next,
        array    $cfg,
        Result   $res
    ): Result
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

    /**
     * @throws Exception
     */
    protected static function opMerge(
        callable $next,
        array    $cfg,
        Result   $res
    ): Result
    {
        $res->checkpointCache();
        Utils::assertImageMimeType($res->mimeType);

        $subRes = static::run(
            $cfg['ops'],
            $res->getReqArgs(),
            $res->getCachePath(),
            $res->getMetadata()
        );

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

    /**
     * @throws Exception
     */
    public static function run(
        array         $ops,
        array         $reqArgs,
        string        $cachePath,
        MetadataScope $meta
    ): Result
    {
        // yeah php, it's a list...
        $ops = array_values($ops);

        if (empty($ops)) {
            throw new RuntimeException('No ops configured');
        }

        $clazz = static::class;

        // using an identity function as the tail of our pipeline
        $next = static function ($res) {
            return $res;
        };

        foreach (array_reverse($ops, true) as $i => $opCfg) {
            if ($i === 0) {
                // it's the first op... run it and the rest of the pipeline
                $res = new Result(
                    $reqArgs,
                    $cachePath,
                    $opCfg['cacheServerName'],
                    new MetadataScope($meta, $opCfg['cacheServerName'])
                );

                try {
                    return static::opSrc($next, $opCfg, $res);
                } catch (Exception $err) {
                    $res->failure = $err;
                    return $res;
                }
            } else {
                $opMethod = 'op' . ucFirst($opCfg['op']);
                unset($opCfg['op']);

                if (!method_exists($clazz, $opMethod)) {
                    throw new RuntimeException('No op method `' . $opMethod . '` defined in `' . $clazz . '`');
                }

                $next = static function ($res) use ($opMethod, $clazz, $next, $opCfg) {
                    return call_user_func([$clazz, $opMethod], $next, $opCfg, $res);
                };
            }
        }

        throw new Exception("Unreachable");
    }

    protected static function opSrc(
        callable $next,
        array    $cfg,
        Result   $res
    ): Result
    {
        global $http_response_header;

        $res->mimeType = $cfg['mimeType'];
        $res->cacheBrowserTtl = $cfg['cacheBrowserTtl'];

        $invalidateCache = (static function () use ($res, $cfg) {
            if (
                is_int($res->getMetadata()->last4xx)
                && time() - ($cfg['cacheServer4xxTtl'] ?? 3600) < $res->getMetadata()->last4xx
            ) {
                // last request failed & we're in the retry cooldown window
                return false;
            }

            if (
                is_int($res->getMetadata()->last5xx)
                && time() - ($cfg['cacheServer5xxTtl'] ?? 300) < $res->getMetadata()->last5xx
            ) {
                // last request failed & we're in the retry cooldown window
                $res->cacheBrowserTtl = $cfg['cacheBrowserTtlFail'];
                return false;
            }

            $mTime = $res->getCacheMTime();

            if ($mTime === null) {
                return true;
            }

            if ($cfg['cacheServerTtl'] < time() - $mTime) {
                return true;
            }

            return false;
        })();

        if ($invalidateCache) {
            $url = static::getSrcUrl($cfg, $res);
            // TODO: send `Accept` header with expected mimetype
            // TODO: check that the mimetype matches the expected mimetype

            $httpResponseHeaderBefore = $http_response_header ?? null;

            $content = @file_get_contents(
                $url,
                false,
                stream_context_create($cfg['streamContext'] ?? [])
            );

            $httpResCode = null;

            if ($http_response_header !== $httpResponseHeaderBefore) {
                // $http_response_header contains our response & `file_get_contents` was an
                // http request

                // https://www.php.net/manual/en/reserved.variables.httpresponseheader.php
                preg_match('/^HTTP\/[\d.]+ (\d{3}) /', $http_response_header[0], $matches);

                $httpResCode = $matches[1] ?? null;
            }

            if ($content === false) {
                if (400 <= $httpResCode && $httpResCode <= 499) {
                    $res->getMetadata()->last4xx = time();
                } else if (500 <= $httpResCode && $httpResCode <= 599) {
                    $res->getMetadata()->last5xx = time();
                    $res->cacheBrowserTtl = $cfg['cacheBrowserTtlFail'];
                }
            } else {
                $res->setData($content);
            }
        }

        return $next($res);
    }

    protected static function getSrcUrl(array $cfg, Result $res): string
    {
        $reqArgs = $res->getReqArgs();
        $url = $cfg['urls'][array_rand($cfg['urls'])];

        if ($reqArgs['prefix'] !== null) {
            $url = str_replace('{prefix}', $reqArgs['prefix'], $url);
        }

        $url = str_replace('{z}', $reqArgs['z'], $url);
        $url = str_replace('{x}', $reqArgs['x'], $url);
        return str_replace('{y}', $reqArgs['y'], $url);
    }
}
