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
* `debug`: (Optional) If set to `true`, outputs exceptions in the browser.
* `prefixArgName`: (Optional) Name of the GET parameter to use for prefixing (e.g., to support different map styles).
* `allowedPrefixes`: (Optional) List of allowed values for the prefix argument.

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
