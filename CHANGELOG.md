# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-06-24

### Added

- `UpstreamFetcher` and `UpstreamFetchResult` for shared upstream HTTP fetching via Guzzle.
- `upstreamHttp` configuration for proxy, timeouts, redirects, and request headers.
- Tile pipeline op handlers in `OpenMapsight\TileProxy\Ops\` (`SrcOp`, `ColorFilterOp`, `ImgOptOp`, `MergeOp`).
- Unit tests for `UpstreamFetcher`.

### Changed

- `Processor` is now a thin pipeline orchestrator; op implementations live in dedicated handler classes.
- Tile `src` fetches and `MapboxStyleProxy` asset fetches both use `UpstreamFetcher`.
- Root-level `upstreamHttp` is passed from `Base::run()` into tile pipelines and merge sub-pipelines.

### Removed

- Per-op `streamContext` support for tile source fetches.

### Migration from 1.x

Replace PHP `streamContext` blocks on `src` operations with root-level `upstreamHttp`:

```jsonc
// before (1.x)
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

// after (2.x)
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
```

Use the same `upstreamHttp` block for Mapbox style proxy deployments.

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
