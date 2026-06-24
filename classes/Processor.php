<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use Exception;
use OpenMapsight\TileProxy\Ops\ColorFilterOp;
use OpenMapsight\TileProxy\Ops\ImgOptOp;
use OpenMapsight\TileProxy\Ops\MergeOp;
use OpenMapsight\TileProxy\Ops\OpHandler;
use OpenMapsight\TileProxy\Ops\SrcOp;
use RuntimeException;

class Processor
{
    /**
     * @return array<string, class-string<OpHandler>>
     */
    protected static function getOpHandlers(): array
    {
        return [
            'colorFilter' => ColorFilterOp::class,
            'imgOpt' => ImgOptOp::class,
            'merge' => MergeOp::class,
        ];
    }

    protected static function createOpHandler(string $op): OpHandler
    {
        $handlers = static::getOpHandlers();

        if (!isset($handlers[$op])) {
            throw new RuntimeException('No op handler for `' . $op . '`');
        }

        $handlerClass = $handlers[$op];

        if ($handlerClass === MergeOp::class) {
            return new MergeOp(static::class);
        }

        return new $handlerClass();
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

        // using an identity function as the tail of our pipeline
        $next = static function ($res) {
            return $res;
        };

        foreach (array_reverse($ops, true) as $i => $opCfg) {
            if ($i === 0) {
                if (!isset($opCfg['cacheServerName'])) {
                    throw new RuntimeException('Missing `cacheServerName` in operation configuration');
                }

                // it's the first op... run it and the rest of the pipeline
                $res = new Result(
                    $reqArgs,
                    $cachePath,
                    $opCfg['cacheServerName'],
                    new MetadataScope($meta, $opCfg['cacheServerName'])
                );

                try {
                    return (new SrcOp())($next, $opCfg, $res);
                } catch (Exception $err) {
                    $res->failure = $err;
                    return $res;
                }
            }

            $op = $opCfg['op'];
            unset($opCfg['op']);

            $handler = static::createOpHandler($op);

            $next = static function ($res) use ($handler, $next, $opCfg) {
                return $handler($next, $opCfg, $res);
            };
        }

        throw new Exception("Unreachable");
    }
}
