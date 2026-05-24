# Tile proxy

## Options

* ***defaultPrefix***: `string`
* ***localPrefixes***: `list<string>`
* ***suffix***: `string`, file name extension (eg. ".png")
* ***mimeType***: `string`, mime type of the image
* ***oldCachePath***: `bool`
* ***cacheDirectoryPath***: `string`
* ***remoteTilesBaseUrls***: `list<string>`
* ***localTilesBaseUrl***: `string`
* ***ttl***: `int`, cache time-to-live in seconds
* ***cachingEnabled***: `bool`, we need *cacheDirectoryPath* even if caching is disabled,
  it's used as a tmp dir in that case.
* ***remoteTilesMap***: `string`, an image map method that is used to alter the downloaded tile,
  allowed values:
    * "none": default
    * "reducedSaturation"
    * "bremen"
    * "knacht"
* ***streamContext***: `map<any, any>`, is used in the context of downloading remote and local
  tiles and gets passed to `stream_context_create`. Can be used to configure a http proxy.
