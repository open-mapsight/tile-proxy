# Tile proxy

Tile proxy is a PHP-based server for processing and caching tiles.

## Usage

You can initialize the proxy by providing a configuration array or by pointing to a JSONC (JSON with comments) configuration file.

### Initialization

Use `Proxy` when a single config serves both raster tile pipelines and Mapbox style assets. It routes style asset
requests under `mapAssetBasePath` to `MapboxStyleProxy` and everything else with an `ops` pipeline to `Base`.

#### Combined or JSONC configuration
```php
use OpenMapsight\TileProxy\Proxy;

Proxy::runFromJsonConfigFile('/path/to/config.jsonc');
```

#### Raster tiles only
```php
use OpenMapsight\TileProxy\Base;

Base::runFromJsonConfigFile('/path/to/config.jsonc');
```

#### Mapbox style assets only
```php
use OpenMapsight\TileProxy\MapboxStyleProxy;

MapboxStyleProxy::run($config, $_SERVER['REQUEST_URI']);
```

#### Using array configuration
```php
use OpenMapsight\TileProxy\Proxy;

$config = [
    // ... configuration ...
];

Proxy::run($config);
```

Both `Proxy`, `Base`, and `MapboxStyleProxy::run()` send HTTP status, cache, and content headers automatically.
Use `MapboxStyleProxy::handleRequest()` or `Base::handleTileRequest()` when you need the `HttpResponse` object without
sending output.

## Configuration

The configuration defines the behavior of the proxy.

* `cacheServerPath`: Base directory for caching tiles and map assets.
* `ops`: (Raster tiles) Operation pipeline for bitmap tile requests.
* `mapAssetBasePath`: (Mapbox styles) URL path prefix for proxied style JSON, vector tiles, sprites, and glyphs.
* `styles`: (Mapbox styles) Named style configurations for `MapboxStyleProxy`.
* `upstreamHttp`: (Optional) Shared HTTP client settings for upstream fetches in tile and Mapbox style proxying.
* `logErrors`: (Optional) If set to `true`, log upstream fetch warnings and request handler failures to PHP's `error_log`.
* `prefixArgName`: (Optional) Name of the GET parameter to use for prefixing (e.g., to support different map styles).
* `allowedPrefixes`: (Optional) List of allowed values for the prefix argument.

### Combined raster tiles and Mapbox styles

Use one JSONC file and `Proxy::runFromJsonConfigFile()` when you need both bitmap tiles and Mapbox/MapLibre style assets.
Root-level settings such as `cacheServerPath`, `upstreamHttp`, and `logErrors` apply to both modes.

```jsonc
{
    "cacheServerPath": "/var/cache/mapsight-tile-proxy",
    "upstreamHttp": {
        "timeout": 30
    },
    "logErrors": true,

    "ops": [
        {
            "cacheServerName": "base-map",
            "urls": ["https://tile.openstreetmap.org/{z}/{x}/{y}.png"],
            "mimeType": "image/png",
            "cacheBrowserTtl": 3600,
            "cacheServerTtl": 86400
        }
    ],

    "mapAssetBasePath": "/map-assets",
    "styles": {
        "city-default": {
            "upstreamStyleUrl": "https://example.com/styles/base.json",
            "allowedHosts": ["example.com"],
            "allowedPathPrefixes": [
                "/styles/",
                "/tiles/",
                "/sprites/",
                "/fonts/"
            ]
        }
    }
}
```

`Proxy` routes by request path:

```text
/tile-proxy.php?z=12&x=2200&y=1340              → raster pipeline (`ops`)
/map-assets/styles/city-default.json            → Mapbox style proxy (`styles`)
/map-assets/tiles/city-default/source/0/12/2200/1340.pbf  → vector tile from style proxy
```

Raster tiles use `x`, `y`, and `z` query parameters. Mapbox assets use path segments under `mapAssetBasePath`. Keep
those URL spaces separate so `Proxy` can pick the right handler.

### Upstream HTTP settings

Both the tile `src` operation and `MapboxStyleProxy` use the same optional `upstreamHttp` configuration for outbound
requests. HTTP(S) fetches go through Guzzle; `file://` URLs are read from disk. Supported keys map to
[Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html): `proxy`, `timeout`,
`connect_timeout`, `allow_redirects`, and `headers`.

```jsonc
"upstreamHttp": {
    "proxy": "tcp://proxy.example.com:8080",
    "timeout": 30,
    "connect_timeout": 10,
    "allow_redirects": true,
    "headers": {
        "User-Agent": "mapsight-tile-proxy"
    }
}
```

For tile pipelines, set `upstreamHttp` at the root of the config. A `src` operation can override it with its own
`upstreamHttp` block.

### Error logging

Most failures are handled gracefully in HTTP responses, but are otherwise silent unless logging is enabled.

When `logErrors` is `true`, or a PSR-3 logger is wired via `Log::setLogger()`, the library logs:

* **Warnings** for upstream transport failures (invalid proxy URI, timeouts, unreadable `file://` URLs, content-type mismatches)
* **Errors** for uncaught request handler failures that become HTTP 500 responses (cache write failures, missing extensions, pipeline misconfiguration)

HTTP 4xx client errors and missing upstream tiles are not logged by default.

Enable PHP `error_log` output in config:

```jsonc
"logErrors": true
```

For production, wire a PSR-3 compatible logger before handling requests. The library calls `warning()` for upstream
issues and `error()` for request handler failures.

#### Plain PHP entry file with Monolog

Install Monolog in your project (`composer require monolog/monolog`), then use a small front script such as
`public/tile-proxy.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use OpenMapsight\TileProxy\Log;
use OpenMapsight\TileProxy\Proxy;

$logger = new Logger('tile-proxy');
$logger->pushHandler(new StreamHandler('php://stderr', Level::Warning));

Log::setLogger($logger);
Proxy::runFromJsonConfigFile(__DIR__ . '/../config/tile-proxy.jsonc');
```

Point your web server at that script (or at `index.php` if you inline the same setup there). Logging to
`php://stderr` works well in Docker; use a file path such as `/var/log/tile-proxy.log` on a normal VM instead.

#### Symfony app logger in a dedicated entry script

Symfony already provides a PSR-3 logger (Monolog). Because `Proxy::run()` sends headers and body itself, call it from
a dedicated entry script rather than returning a Symfony `Response`:

```php
<?php
declare(strict_types=1);

use OpenMapsight\TileProxy\Log;
use OpenMapsight\TileProxy\Proxy;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Kernel;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var Kernel $kernel */
$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

/** @var LoggerInterface $logger */
$logger = $kernel->getContainer()->get('logger');

Log::setLogger($logger);
Proxy::runFromJsonConfigFile(dirname(__DIR__) . '/config/tile-proxy.jsonc');
```

Route `/tiles` or `/map-assets` directly to that script in nginx or Apache. In a Symfony controller action the same
logger wiring works, but you would need to capture output yourself via `Base::handleTileRequest()` or
`MapboxStyleProxy::handleRequest()` instead of `Proxy::run()`.

Failed upstream fetches also expose a short reason on `UpstreamFetchResult::$error` when you call `UpstreamFetcher` directly.

### Operation Pipeline (Chaining)

Operations are chained sequentially as defined in the `ops` array. The first operation must be the `src` operation, which defines the source URL(s).

```jsonc
"ops": [
    {
        "cacheServerName": "source-1",
        "urls": ["https://example.com/tiles/{z}/{x}/{y}.png"],
        "mimeType": "image/png",
        "cacheBrowserTtl": 3600,
        "cacheServerTtl": 86400
    },
    {
        "op": "colorFilter",
        "filter": "reducedSaturation",
        "cacheServerName": "filter-1"
    },
    {
        "op": "imgOpt",
        "cacheServerName": "opt-1"
    }
]
```

### Available Operations

* `src`: Fetches the tile from the defined `urls`. Supports `{z}`, `{x}`, `{y}`, and `{prefix}` placeholders.
* `colorFilter`: Applies color filters. Supported filters: `reducedSaturation`, `muted`, `culture`.
* `imgOpt`: Optimizes the image using image optimizers.
* `merge`: Merges the current tile with another set of operations.

Any operation may include an optional `prefixes` array. When set, the operation runs only when the resolved tile
prefix (from `prefixArgName` / `defaultPrefix`) is listed. This applies to sub-pipelines inside `merge` as well.

## Mapbox Style Vector Proxy

`MapboxStyleProxy` proxies named Mapbox/MapLibre style assets through same-origin URLs. It rewrites style JSON
references for TileJSON, vector tiles, sprites, and glyphs, resolves relative asset URLs, then fetches only allow-listed
upstream URLs on demand.

```php
use OpenMapsight\TileProxy\MapboxStyleProxy;

MapboxStyleProxy::run($config, $_SERVER['REQUEST_URI']);
```

For custom response handling:

```php
use OpenMapsight\TileProxy\HttpResponse;
use OpenMapsight\TileProxy\MapboxStyleProxy;

$response = MapboxStyleProxy::handleRequest($config, $_SERVER['REQUEST_URI']);
HttpResponse::send($response);
```

Example configuration:

```jsonc
{
    "cacheServerPath": "/var/cache/mapsight-tile-proxy",
    "mapAssetBasePath": "/map-assets",
    "upstreamHttp": {
        "proxy": "tcp://proxy.example.com:8080",
        "timeout": 30
    },
    "styles": {
        "city-default": {
            "upstreamStyleUrl": "https://example.com/styles/base.json",
            "allowedHosts": ["example.com"],
            "allowedPathPrefixes": [
                "/styles/",
                "/tiles/",
                "/sprites/",
                "/fonts/"
            ],
            "cacheTtls": {
                "style": 86400,
                "tilejson": 86400,
                "tile": 604800,
                "sprite": 2592000,
                "glyph": 2592000
            },
            "transforms": [
                { "op": "removeLayersById", "ids": ["duplicate-poi-layer"] },
                { "op": "removeLayersByIdContains", "contains": "RailTrack" }
            ],
            "attribution": "City map attribution, Darstellung verandert"
        }
    }
}
```

Request the proxied style at:

```text
/map-assets/styles/city-default.json
```

Generated asset routes use the same base path:

```text
/map-assets/tilejson/{styleName}/{sourceName}.json
/map-assets/tiles/{styleName}/{sourceName}/{tileIndex}/{z}/{x}/{y}.pbf
/map-assets/sprites/{styleName}/{encodedSpriteUrl}.json
/map-assets/sprites/{styleName}/{encodedSpriteUrl}.png
/map-assets/glyphs/{styleName}/{encodedGlyphTemplate}/{fontstack}/{range}.pbf
```

## Examples

### Layering Tiles

You can layer tiles by using the `merge` operation to overlay another tile set on top of the base map. If the overlay request fails (e.g., returns a 404), the merge operation is skipped and the base tile is returned.

```jsonc
"ops": [
    {
        "cacheServerName": "base-map",
        "urls": ["https://tile.openstreetmap.org/{z}/{x}/{y}.png"],
        "mimeType": "image/png",
        "cacheBrowserTtl": 3600,
        "cacheServerTtl": 86400
    },
    {
        "op": "merge",
        "ops": [
            {
                "cacheServerName": "overlay",
                "urls": ["https://overlay.example.com/{z}/{x}/{y}.png"],
                "mimeType": "image/png",
                "cacheBrowserTtl": 3600,
                "cacheServerTtl": 86400
            }
        ]
    }
]
```

### Prefix-specific operations

Use `prefixes` to run an operation only for certain map styles. For example, apply a color filter to the base map
before merging an overlay when `prefix=muted`:

```jsonc
"ops": [
    {
        "cacheServerName": "base-map",
        "urls": ["https://tile.openstreetmap.org/{z}/{x}/{y}.png"],
        "mimeType": "image/png",
        "cacheBrowserTtl": 3600,
        "cacheServerTtl": 86400
    },
    {
        "op": "colorFilter",
        "prefixes": ["muted"],
        "filter": "reducedSaturation",
        "cacheServerName": "desaturated"
    },
    {
        "op": "merge",
        "ops": [
            {
                "cacheServerName": "overlay",
                "urls": ["https://example.com/tiles/{prefix}/{z}/{x}/{y}.png"],
                "mimeType": "image/png",
                "cacheBrowserTtl": 3600,
                "cacheServerTtl": 86400
            }
        ]
    },
    {
        "op": "imgOpt",
        "cacheServerName": "opt-1"
    }
],
"prefixArgName": "prefix",
"allowedPrefixes": ["default", "satellite", "muted"],
"defaultPrefix": "default"
```

## Development

### Testing

To run the tests, use:

```bash
composer test
```

Or run PHPUnit directly:

```bash
vendor/bin/phpunit
```

The basemap.de integration test is skipped by default because it depends on the live upstream service. Run it explicitly
with:

```bash
RUN_BASEMAPDE_INTEGRATION_TESTS=1 vendor/bin/phpunit tests/BasemapDeIntegrationTest.php
```
