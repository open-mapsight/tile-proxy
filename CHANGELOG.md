# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.1] - 2026-06-25

### Added

- `laxContentTypes` configuration for Mapbox style proxying. When enabled, vector tile and glyph fetches also accept
  `application/octet-stream` upstream responses. Supports a boolean or a list of additional MIME types per style.

## [2.1.0] - 2026-06-25

### Added

- Optional `prefixes` array on pipeline operations. When set, the operation runs only for listed tile prefixes.

## [2.0.0] - 2026-06-24

### Added

- Root-level `upstreamHttp` configuration for proxy, timeouts, redirects, and request headers. When set, it applies to tile source fetches and `MapboxStyleProxy` unless a `src` operation defines its own `upstreamHttp` block.
- `logErrors` configuration and optional PSR-3 logging via `Log::setLogger()` for upstream fetch warnings and request handler failures.
- `HttpResponse` value object with shared HTTP header/output handling.
- `Proxy` entry point for combined raster tile and Mapbox style asset configs.
- `MapboxStyleProxy::run()` for map asset requests with automatic response headers.
- `mapAssetBasePath` configuration for the URL prefix of proxied Mapbox/MapLibre style assets.

### Changed

- **Breaking:** tile source HTTP options use `upstreamHttp` instead of PHP `streamContext` (see migration below). Per-op overrides on `src` operations are still supported.
- **Breaking:** `publicBasePath` renamed to `mapAssetBasePath`. The old key still works as a fallback.
- `ext-imagick` is no longer a required dependency; install it only when using `colorFilter` ops (missing extension throws a clear runtime error).
- Upstream responses with an unexpected `Content-Type` are treated as fetch failures.
- Invalid JSONC config and failed cache file writes now throw exceptions instead of failing silently.
- `cacheBrowserTtlFail` on `src` ops defaults to 300 seconds when omitted.
- `Base::run()`, `MapboxStyleProxy::run()`, and `Proxy::run()` send HTTP responses through `HttpResponse::sendRequest()`.

### Removed

- Per-op `streamContext` on tile `src` operations (replaced by `upstreamHttp`).
- `debug` configuration option that printed exceptions in the browser response. Use `logErrors` or `Log::setLogger()` instead.
- `TileResponse` and `MapboxStyleProxyResponse` (replaced by `HttpResponse`).

### Migration from 1.x

Rename `streamContext` to `upstreamHttp` and convert PHP stream-context keys to Guzzle-style options. You can set shared defaults at the config root, keep per-`src` overrides, or both:

```jsonc
// before (1.x) — per-op only
"ops": [{
    "cacheServerName": "source-1",
    "urls": ["https://example.com/tiles/{z}/{x}/{y}.png"],
    "streamContext": {
        "http": {
            "proxy": "tcp://proxy.example.com:8080",
            "timeout": 30,
            "user_agent": "mapsight-tile-proxy"
        }
    }
}]

// after (2.x) — shared defaults at root
"upstreamHttp": {
    "proxy": "tcp://proxy.example.com:8080",
    "timeout": 30,
    "headers": {
        "User-Agent": "mapsight-tile-proxy"
    }
},
"ops": [{
    "cacheServerName": "source-1",
    "urls": ["https://example.com/tiles/{z}/{x}/{y}.png"]
}]

// after (2.x) — per-src override (same as 1.x, new format)
"upstreamHttp": {
    "timeout": 30,
    "headers": {
        "User-Agent": "mapsight-tile-proxy"
    }
},
"ops": [{
    "cacheServerName": "corp-tiles",
    "urls": ["https://tiles.internal.example/{z}/{x}/{y}.png"],
    "upstreamHttp": {
        "proxy": "tcp://corp-proxy.example.com:8080",
        "timeout": 30,
        "headers": {
            "User-Agent": "mapsight-tile-proxy"
        }
    }
}, {
    "cacheServerName": "public-tiles",
    "urls": ["https://tiles.example.com/{z}/{x}/{y}.png"]
}]
```

In the per-src example, `corp-tiles` uses its own `upstreamHttp` block; `public-tiles` inherits the root defaults. Use separate `src` operations (including in `merge` sub-pipelines) when different upstreams need different HTTP settings.

Use the same root `upstreamHttp` block for Mapbox style proxy deployments.

Replace `debug: true` with logging:

```jsonc
"logErrors": true
```

Or wire a PSR-3 logger in your bootstrap PHP before calling `Proxy::run()`, `Base::run()`, or `MapboxStyleProxy::run()`.

For combined tile and Mapbox style configs, prefer `Proxy::runFromJsonConfigFile()` over calling `Base` and `MapboxStyleProxy` separately.

Rename `publicBasePath` to `mapAssetBasePath` in Mapbox style configs. The old key still works in 2.0.

## [1.1.0] - 2026-06-22

### Added

- `MapboxStyleProxy` for proxying Mapbox/MapLibre style assets through same-origin URLs.
- `MapboxStyleProxyResponse` value object for proxied asset responses.
- Support for style JSON, TileJSON, vector tiles, sprites, and glyphs with allow-listed upstream hosts and path prefixes.
- Server-side style transforms: `removeLayersById`, `removeLayersByIdContains`, and `removeLayersByIdPrefixExcept`.
- Optional attribution metadata injection via style configuration.
- On-demand disk caching with configurable TTLs per resource type.
- README documentation and unit tests for the Mapbox style proxy.
- Opt-in basemap.de integration test (`RUN_BASEMAPDE_INTEGRATION_TESTS=1`).

## [1.0.2] - 2026-05-24

### Added

- Tests for README configuration examples.
- `.gitattributes` export rules for Composer archives.

### Changed

- Improved error handling when `cacheServerName` is missing.
- Documented tile layering and the `merge` operation in README.

### Fixed

- Miscellaneous cleanup and fixes.

## [1.0.1] - 2026-05-24

### Changed

- Cleanup, fixes, and README updates.

## [1.0.0] - 2026-05-24

### Added

- Initial release of the PHP tile proxy with operation pipeline, caching, and tests.

[2.1.1]: https://github.com/open-mapsight/tile-proxy/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/open-mapsight/tile-proxy/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/open-mapsight/tile-proxy/compare/v1.1.0...v2.0.0
[1.1.0]: https://github.com/open-mapsight/tile-proxy/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/open-mapsight/tile-proxy/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/open-mapsight/tile-proxy/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/open-mapsight/tile-proxy/releases/tag/v1.0.0
