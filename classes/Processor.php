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

class Processor implements PipelineRunner
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

    protected static function createOpHandler(string $op, array $upstreamHttp = []): OpHandler
    {
        $handlers = static::getOpHandlers();

        if (!isset($handlers[$op])) {
            throw new RuntimeException('No op handler for `' . $op . '`');
        }

        $handlerClass = $handlers[$op];

        if ($handlerClass === MergeOp::class) {
            return new MergeOp(static::class, $upstreamHttp);
        }

        return new $handlerClass();
    }

    /**
     * @param list<array<string, mixed>> $ops
     * @return list<array<string, mixed>>
     */
    protected static function filterOpsByPrefix(array $ops, ?string $prefix): array
    {
        $filtered = [];

        foreach ($ops as $opCfg) {
            if (!static::opMatchesPrefix($opCfg, $prefix)) {
                continue;
            }

            unset($opCfg['prefixes']);
            $filtered[] = $opCfg;
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $opCfg
     */
    protected static function opMatchesPrefix(array $opCfg, ?string $prefix): bool
    {
        if (!isset($opCfg['prefixes'])) {
            return true;
        }

        if (!is_array($opCfg['prefixes'])) {
            throw new RuntimeException('`prefixes` must be an array');
        }

        if ($prefix === null) {
            return false;
        }

        foreach ($opCfg['prefixes'] as $allowedPrefix) {
            if (!is_string($allowedPrefix)) {
                throw new RuntimeException('`prefixes` entries must be strings');
            }
        }

        return in_array($prefix, $opCfg['prefixes'], true);
    }

    /**
     * @throws Exception
     */
    public static function run(
        array         $ops,
        array         $reqArgs,
        string        $cachePath,
        MetadataScope $meta,
        array         $upstreamHttp = []
    ): Result
    {
        // yeah php, it's a list...
        $ops = static::filterOpsByPrefix(array_values($ops), $reqArgs['prefix'] ?? null);

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
                    $srcCfg = $opCfg;
                    if (!isset($srcCfg['upstreamHttp']) && $upstreamHttp !== []) {
                        $srcCfg['upstreamHttp'] = $upstreamHttp;
                    }

                    return (new SrcOp())($next, $srcCfg, $res);
                } catch (Exception $err) {
                    $res->failure = $err;
                    return $res;
                }
            }

            $op = $opCfg['op'];
            unset($opCfg['op']);

            $handler = static::createOpHandler($op, $upstreamHttp);

            $next = static function ($res) use ($handler, $next, $opCfg) {
                return $handler($next, $opCfg, $res);
            };
        }

        throw new Exception("Unreachable");
    }
}
