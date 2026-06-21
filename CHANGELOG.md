# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.1.0]: https://github.com/open-mapsight/tile-proxy/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/open-mapsight/tile-proxy/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/open-mapsight/tile-proxy/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/open-mapsight/tile-proxy/releases/tag/v1.0.0
