<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use RuntimeException;
use Throwable;

class Base
{
    public static function runFromJsonConfigFile(
        string $cfgPath,
        string $processorClass = Processor::class
    ): void
    {
        $cfg = file_get_contents($cfgPath);
        if ($cfg === false) {
            throw new RuntimeException('Could not read config file "' . $cfgPath . '"');
        }

        $cfg = Utils::parseJsoncString($cfg);
        static::run($cfg, $processorClass);
    }

    public static function run(
        array  $cfg,
        string $processorClass = Processor::class
    ): void
    {
        HttpResponse::sendRequest(
            $cfg,
            static fn () => static::handleTileRequest($cfg, $processorClass)
        );
    }

    /**
     * @throws Throwable
     */
    public static function handleTileRequest(
        array  $cfg,
        string $processorClass = Processor::class
    ): HttpResponse
    {
        $reqArgs = static::getReqArgs($cfg);
        $cachePath = static::getCachePath($cfg, $reqArgs);

        $metadataPath = $cachePath . '-.metadata';
        Utils::mkdirp(dirname($cachePath));
        $lockH = fopen($metadataPath, 'cb');
        for ($lockTry = 1; ; ++$lockTry) {
            if (flock($lockH, LOCK_EX | LOCK_NB) === true) {
                break;
            }

            if (10 <= $lockTry) {
                if (isset($cfg['yoloOnLockTimeout']) && $cfg['yoloOnLockTimeout'] === true) {
                    @error_log('Yoloed lock for "' . $metadataPath . '"' . "\n");
                    break;
                }

                throw new RuntimeException('Can not lock "' . $metadataPath . '"');
            }

            usleep($lockTry * $lockTry * 10 * 1000);
        }

        try {
            $meta = new Metadata($metadataPath);

            /** @var Result $res */
            $res = call_user_func(
                [$processorClass, 'run'],
                $cfg['ops'],
                $reqArgs,
                $cachePath,
                new MetadataScope($meta, ''),
                $cfg['upstreamHttp'] ?? []
            );

            if ($res->failure !== null) {
                throw $res->failure;
            }

            $res->checkpointCache();
            Metadata::save($meta);

            return static::buildTileResponse($res);
        } finally {
            flock($lockH, LOCK_UN);
        }
    }

    public static function buildTileResponse(Result $res): HttpResponse
    {
        $data = $res->getData();
        if ($data === null) {
            return new HttpResponse(null, null, null, null);
        }

        assert($res->mimeType !== null);

        $mTime = $res->getCacheMTime();
        $notModified = !empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])
            && $mTime !== null
            && $mTime <= @strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);

        return new HttpResponse(
            $notModified ? null : $data,
            $res->mimeType,
            $res->cacheBrowserTtl,
            $mTime,
            $notModified
        );
    }

    /** @deprecated Use HttpResponse::send() */
    public static function sendTileResponse(HttpResponse $response): void
    {
        HttpResponse::send($response);
    }

    /** @return array<string, string|null> */
    protected static function getReqArgs(array $cfg): array
    {
        $prefix = (static function () use ($cfg) {
            if (!empty($cfg['prefixArgName']) && !empty($_GET[$cfg['prefixArgName']])) {
                if (!is_array($cfg['allowedPrefixes'])) {
                    throw new RuntimeException('prefixArgName is defined, but allowedPrefixes is not');
                }
                $prefix = (string)$_GET[$cfg['prefixArgName']];
                if (in_array($prefix, $cfg['allowedPrefixes'], true)) {
                    return $prefix;
                }

                throw new UserException('Prefix "' . $prefix . '" not allowed');
            }

            return $cfg['defaultPrefix'] ?? null;
        })();

        return [
            'x' => (string)$_GET['x'],
            'y' => (string)$_GET['y'],
            'z' => (string)$_GET['z'],
            'prefix' => $prefix !== null ? (string)$prefix : null,
        ];
    }

    protected static function getCachePath(array $cfg, array $reqArgs): string
    {
        $path = $cfg['cacheServerPath'];

        if ($reqArgs['prefix'] !== null) {
            $path .= '/' . $reqArgs['prefix'];
        }

        $path .= '/' . $reqArgs['z'] . '/' . $reqArgs['x'] . '/' . $reqArgs['y'];

        return $path;
    }
}
