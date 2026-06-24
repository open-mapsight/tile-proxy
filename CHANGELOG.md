# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-06-24

### Added

- Root-level `upstreamHttp` configuration for proxy, timeouts, redirects, and request headers. When set, it applies to tile source fetches and `MapboxStyleProxy` unless a `src` operation defines its own `upstreamHttp` block.

### Changed

- **Breaking:** tile source HTTP options use `upstreamHttp` instead of PHP `streamContext` (see migration below). Per-op overrides on `src` operations are still supported.
- `ext-imagick` is no longer a required dependency; install it only when using `colorFilter` ops (missing extension throws a clear runtime error).
- Upstream responses with an unexpected `Content-Type` are treated as fetch failures.
- Invalid JSONC config and failed cache file writes now throw exceptions instead of failing silently.
- `cacheBrowserTtlFail` on `src` ops defaults to 300 seconds when omitted.

### Removed

- Per-op `streamContext` on tile `src` operations (replaced by `upstreamHttp`).

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

[2.0.0]: https://github.com/open-mapsight/tile-proxy/compare/v1.1.0...v2.0.0
[1.1.0]: https://github.com/open-mapsight/tile-proxy/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/open-mapsight/tile-proxy/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/open-mapsight/tile-proxy/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/open-mapsight/tile-proxy/releases/tag/v1.0.0
