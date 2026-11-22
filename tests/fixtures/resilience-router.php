<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use OpenMapsight\TileProxy\HttpResponse;
use OpenMapsight\TileProxy\Proxy;
use OpenMapsight\TileProxy\UpstreamFetcher;
use Psr\Http\Message\RequestInterface;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/legacy') {
    HttpResponse::send(new HttpResponse(null, null, 75, null));
    return;
}

UpstreamFetcher::setHttpClient(new Client([
    'handler' => static fn (RequestInterface $request) => Create::promiseFor(new Response(
        $path === '/huge-404' || str_ends_with($request->getUri()->getPath(), '.pbf') ? 404 : 503
    )),
    'http_errors' => false,
]));
$cacheRoot = $_SERVER['DOCUMENT_ROOT'] . '/cache';
$config = [
    'cacheServerPath' => $cacheRoot,
    'cacheLockTimeout' => 0,
    'mapAssetBasePath' => '/map-assets',
    'ops' => [[
        'cacheServerName' => 'raster',
        'urls' => ['https://tiles.test/{z}/{x}/{y}.png'],
        'mimeType' => 'image/png',
        'cacheBrowserTtl' => 3600,
        'cacheServerTtl' => 3600,
        'upstreamCircuitBreaker' => ['failureThreshold' => 1],
    ]],
];
if ($path === '/huge-404') {
    $config['ops'][0]['cacheBrowser404Ttl'] = PHP_INT_MAX;
}

$writeCache = static function (
    string $style, string $type, string $url, array $body, int $age
) use ($cacheRoot): void {
    $dir = $cacheRoot . '/mapbox-style-proxy/' . $style . '/' . $type;
    $file = $dir . '/' . sha1($url);
    if (!is_file($file)) {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($file, json_encode($body, JSON_THROW_ON_ERROR));
        touch($file, time() - $age);
    }
};

foreach (['direct', 'indirect', 'zero', 'cold', 'collision'] as $style) {
    $origin = 'https://tiles.test/' . $style . '/';
    $config['styles'][$style] = [
        'upstreamStyleUrl' => $origin . 'style.json',
        'allowedHosts' => ['tiles.test'],
        'allowedPathPrefixes' => ['/' . $style . '/'],
        'cacheServer404Ttl' => 3600,
        'cacheBrowser404Ttl' => $style === 'collision' ? 0 : 3600,
        'upstreamFailureCache' => $style === 'collision' ? [] : ['cacheBrowserTtlFail' => $style === 'zero' ? 0 : 7],
    ];
    if ($style === 'cold') {
        continue;
    }

    $tiles = [$origin . 'tile/{z}/{x}/{y}.pbf'];
    $source = $style === 'indirect' ? ['url' => $origin . 'tilejson.json'] : ['tiles' => $tiles];
    $writeCache($style, 'style', $origin . 'style.json', [
        'version' => 8,
        'sources' => ['source' => ['type' => 'vector'] + $source],
        'layers' => [],
    ], in_array($style, ['indirect', 'collision'], true) ? 0 : 90000);
    if ($style === 'indirect') {
        $writeCache($style, 'tilejson', $origin . 'tilejson.json', ['tiles' => $tiles], 90000);
    }
}

Proxy::run($config);
