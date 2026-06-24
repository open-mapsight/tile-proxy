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
        try {
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
                $response = static::buildTileResponse($res);
            } finally {
                flock($lockH, LOCK_UN);
            }

            static::sendTileResponse($response);
        } catch (UserException $e) {
            header('HTTP/1.0 400 Bad Request', true, 400);
            echo $e->getMessage();
        } catch (Throwable $e) {
            header('HTTP/1.0 500 Internal Server Error', true, 500);
            if (isset($cfg['debug']) && $cfg['debug'] === true) {
                echo '<pre>';
                echo $e;
                echo '</pre>';
            }
        }
    }

    public static function buildTileResponse(Result $res): TileResponse
    {
        $data = $res->getData();
        if ($data === null) {
            return new TileResponse(null, null, null, null);
        }

        assert($res->mimeType !== null);

        $mTime = $res->getCacheMTime();
        $notModified = !empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])
            && $mTime !== null
            && $mTime <= @strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);

        return new TileResponse(
            $notModified ? null : $data,
            $res->mimeType,
            $res->cacheBrowserTtl,
            $mTime,
            $notModified
        );
    }

    public static function sendTileResponse(TileResponse $response): void
    {
        if ($response->isNotFound()) {
            header('HTTP/1.0 404 Not Found', true, 404);
            echo 'tile not found';
            return;
        }

        assert($response->mimeType !== null);

        $time = time();

        header('Content-Type: ' . $response->mimeType);

        if ($response->cacheBrowserTtl !== null) {
            header('Expires: ' . gmdate('D, d M Y H:i:s', $time + $response->cacheBrowserTtl) . ' GMT');
            header('Cache-Control: public, max-age=' . $response->cacheBrowserTtl);
        }

        if ($response->cacheMTime !== null) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $response->cacheMTime) . ' GMT');
        }

        if ($response->notModified) {
            header('HTTP/1.1 304 Not Modified');
            return;
        }

        assert($response->body !== null);
        header('Content-Length: ' . strlen($response->body));
        echo $response->body;
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
