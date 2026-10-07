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
* `cacheLockTimeout`: (Raster tiles, optional) Maximum seconds to wait for a tile's exclusive cache lock.
  Defaults to `2.85` for compatibility; set it above the full upstream fetch and processing budget.
* `ops`: (Raster tiles) Operation pipeline for bitmap tile requests.
* `mapAssetBasePath`: (Mapbox styles) URL path prefix for proxied style JSON, vector tiles, sprites, and glyphs.
* `styles`: (Mapbox styles) Named style configurations for `MapboxStyleProxy`.
* `laxContentTypes`: (Mapbox styles) When `true`, accept `application/octet-stream` for vector tile and glyph
  upstream responses in addition to the expected protobuf type. Set per style or at the config root. Can also be an
  array of additional MIME types to accept for any validated upstream fetch in that style.
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

### Raster cache retention

`cacheServerTtl` controls freshness: an expired tile is refreshed on its next request, and a failed refresh can
still serve its cached copy. It does not evict unused files.

Call `CachePruner::prune()` from cron or your application's scheduler to remove raster tile files older than a
separate retention period. This includes obsolete `cacheServerName` namespaces, old metadata without retained
tiles, and empty directories. The return value counts deleted files. For example, run this daily:

```php
use OpenMapsight\TileProxy\CachePruner;

$deleted = CachePruner::prune('/var/cache/mapsight-tile-proxy', 30 * 86400);
```

Choose a retention period longer than your raster `cacheServerTtl` values and any upstream minimum caching
requirements. Age is measured from tile writes, not filesystem atime; metadata tracks the last completed request.
Retention limits the age of stored tiles, not total disk usage. Mapbox style assets are outside this raster cleanup.

The pruner takes the same per-tile lock as `Base`, skips busy tiles without waiting, and rechecks timestamps under
the lock. Waiting requests reopen metadata that was removed by the pruner before using the cache. Run cleanup
only after all processes sharing the cache use this version's locking, and leave `yoloOnLockTimeout` disabled.
Use a POSIX filesystem supporting `flock()` across those processes. Cache directories are reserved for proxy files;
symlinks are not followed by the pruner.

For an upstream timeout of ten seconds plus tile processing, set `cacheLockTimeout` to a larger budget, for example:

```jsonc
"cacheLockTimeout": 15,
"upstreamHttp": { "timeout": 10 }
```

Zero means a single non-blocking lock attempt. Negative or non-finite timeouts are rejected. Locks are closed on
both successful requests and failures; the default remains 2.85 seconds for existing deployments.

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

* `src`: Fetches the tile from the defined `urls`, or from a `wms` GetMap source. URL templates support `{z}`, `{x}`, `{y}`, `{prefix}`, `{bbox}` / `{bbox3857}` (Web Mercator meters), and `{bbox4326}` (lon,lat,lon,lat).
* `colorFilter`: Applies color filters. Supported filters: `reducedSaturation`, `muted`, `culture`.
* `colorKey`: Makes nodata fill colors transparent (`colors` as `#RRGGBB`, optional `fuzz`, optional `fromEdges`). Defaults to a flood from the tile edge and already-transparent pixels so isolated interior matches stay put. Always encodes PNG. Optional `soft` fades alpha by distance to the key color instead of a hard cut. Optional `feather` (integer pixel passes) blurs alpha afterward. Optional `protectDarkerThan` (0–255 luminance) restores partial-alpha pixels darker than that value so labels stay opaque after a street-fill punch.
* `encode`: Re-encodes the current tile to another image type (`mimeType`, optional `quality` for JPEG/WebP).
* `imgOpt`: Optimizes the image using image optimizers.
* `merge`: Merges the current tile with another set of operations.

Any operation may include an optional `prefixes` array. When set, the operation runs only when the resolved tile
prefix (from `prefixArgName` / `defaultPrefix`) is listed. This applies to sub-pipelines inside `merge` as well.

### WMS as public XYZ tiles

The public request stays a normal OSM-style XYZ tile (`?z=&x=&y=` or a `/tiles/{z}/{x}/{y}.jpg` rewrite). The `src` operation turns that tile into a WMS `GetMap` bbox and fetches one 256×256 image.

Use a `wms` block for the common GeoServer case. The default CRS is `EPSG:3857`, which matches slippy-map tiles:

```jsonc
"ops": [
    {
        "cacheServerName": "luftbild-2024",
        "wms": {
            "url": "https://geoportal.example.de/geoserver/luftbilder/wms",
            "layers": "Luftbild_2024",
            "version": "1.1.1",
            "format": "image/jpeg",
            "srs": "EPSG:3857",
            "transparent": false
        },
        "mimeType": "image/jpeg",
        "cacheBrowserTtl": 86400,
        "cacheServerTtl": 604800
    }
]
```

`format` defaults to the `src` `mimeType` when omitted. Supported `srs` values are `EPSG:3857`, `EPSG:4326`, and `CRS:84`. WMS 1.3.0 uses `CRS` and the EPSG:4326 lat/lon axis order.

Or keep a full URL template when you need extra query parameters:

```jsonc
"urls": [
    "https://geoportal.example.de/geoserver/luftbilder/wms?REQUEST=GetMap&SERVICE=WMS&VERSION=1.1.1&FORMAT=image/jpeg&STYLES=&TRANSPARENT=false&LAYERS=Luftbild_2024&WIDTH=256&HEIGHT=256&SRS=EPSG:3857&BBOX={bbox3857}"
]
```

JPEG is the right WMS `FORMAT` for aerial photos (smaller than PNG, widely supported by GeoServer). WebP is rarely offered by WMS. Fetch JPEG, then `encode` if you want to serve WebP tiles to the browser:

```jsonc
"ops": [
    {
        "cacheServerName": "luftbild-2024",
        "wms": {
            "url": "https://geoportal.example.de/geoserver/luftbilder/wms",
            "layers": "Luftbild_2024",
            "format": "image/jpeg"
        },
        "mimeType": "image/jpeg",
        "cacheBrowserTtl": 86400,
        "cacheServerTtl": 604800
    },
    {
        "op": "encode",
        "mimeType": "image/webp",
        "quality": 80,
        "cacheServerName": "luftbild-webp"
    }
]
```

To punch a solid nodata fill out of an aerial before overlaying it, add `colorKey`. `fuzz` is a per-channel tolerance. Keep `fromEdges` at its default (`true`) so interior roofs or shadows that happen to match the fill stay put:

```jsonc
{
    "op": "colorKey",
    "colors": ["#000000"],
    "fuzz": 8,
    "cacheServerName": "luftbild-keyed"
}
```

Set `fromEdges` to `false` only when every matching pixel should become transparent, including interior matches.

To punch cartographic fills (street interiors) and keep labels, turn off the edge flood, fade the cut, then protect dark ink:

```jsonc
{
    "op": "colorKey",
    "colors": ["#ffffff", "#fff8bd", "#d0d0d0"],
    "fuzz": 8,
    "fromEdges": false,
    "soft": true,
    "feather": 2,
    "protectDarkerThan": 80,
    "cacheServerName": "stadtplan-ink"
}
```

Leaflet, OpenLayers, and MapLibre can consume the proxied XYZ URL like any other raster tileset.

If the WMS requires Basic auth, set `upstreamHttp.headers.Authorization` in the PHP bootstrap (not in committed JSONC). Some municipal WMS licenses also forbid local storage — disable or shorten `cacheServerTtl` when that applies.

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
            "laxContentTypes": true,
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

## License

This project is **[MIT](LICENSE)**.

For licensing or trademark questions, contact
[contact@open-mapsight.org](mailto:contact@open-mapsight.org).

## Community

Participation in Mapsight community spaces is governed by our
[Code of Conduct](CODE_OF_CONDUCT.md) (Contributor Covenant 3.0).
