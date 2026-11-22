<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use OpenMapsight\TileProxy\UpstreamCircuitBreaker;
use OpenMapsight\TileProxy\UpstreamFetchResult;

[, $root, $mode] = $argv;
$breaker = new UpstreamCircuitBreaker($root, ['failureThreshold' => 100]);
echo "ready\n";
fgets(STDIN);

$result = $breaker->fetch('src', 'https://origin.test/burst', static function () use ($mode): UpstreamFetchResult {
    echo "fetched\n";
    if ($mode === 'complete') {
        fgets(STDIN);
    }
    return new UpstreamFetchResult(null, 503, false);
});
echo $result === null ? "skipped\n" : "done\n";
