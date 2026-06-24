# Tile proxy

Tile proxy is a PHP-based server for processing and caching tiles.

## Usage

You can initialize the proxy by providing a configuration array or by pointing to a JSONC (JSON with comments) configuration file.

### Initialization

#### Using JSONC Configuration
```php
use OpenMapsight\TileProxy\Base;

Base::runFromJsonConfigFile('/path/to/config.jsonc');
```

#### Using Array Configuration
```php
use OpenMapsight\TileProxy\Base;

$config = [
    // ... configuration ...
];

Base::run($config);
```

## Configuration

The configuration defines the behavior of the proxy.

* `cacheServerPath`: Base directory for caching tiles.
* `ops`: A list of operations to perform on the tiles.
* `upstreamHttp`: (Optional) Shared HTTP client settings for upstream fetches in tile and Mapbox style proxying.
* `debug`: (Optional) If set to `true`, outputs exceptions in the browser.
* `prefixArgName`: (Optional) Name of the GET parameter to use for prefixing (e.g., to support different map styles).
* `allowedPrefixes`: (Optional) List of allowed values for the prefix argument.

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

## Mapbox Style Vector Proxy

`MapboxStyleProxy` proxies named Mapbox/MapLibre style assets through same-origin URLs. It rewrites style JSON
references for TileJSON, vector tiles, sprites, and glyphs, resolves relative asset URLs, then fetches only allow-listed
upstream URLs on demand.

```php
use OpenMapsight\TileProxy\MapboxStyleProxy;

$response = MapboxStyleProxy::handleRequest($config, $_SERVER['REQUEST_URI']);

header('Content-Type: ' . $response->mimeType);
if ($response->cacheBrowserTtl !== null) {
    header('Cache-Control: public, max-age=' . $response->cacheBrowserTtl);
}
echo $response->data;
```

Example configuration:

```jsonc
{
    "cacheServerPath": "/var/cache/mapsight-tile-proxy",
    "publicBasePath": "/map-assets",
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
