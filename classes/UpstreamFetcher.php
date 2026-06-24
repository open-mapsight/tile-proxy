<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class UpstreamFetcher
{
    /**
     * @param array<string, mixed> $httpConfig `upstreamHttp` configuration
     */
    public static function fetch(string $url, array $httpConfig = [], ?string $acceptMimeType = null): UpstreamFetchResult
    {
        if (parse_url($url, PHP_URL_SCHEME) === 'file') {
            $content = @file_get_contents($url);
            if ($content === false) {
                return new UpstreamFetchResult(null, null, true);
            }

            return new UpstreamFetchResult($content, 200, false);
        }

        try {
            $options = static::guzzleOptionsFromHttpConfig($httpConfig);

            if ($acceptMimeType !== null) {
                $options['headers'] ??= [];
                $options['headers']['Accept'] = $acceptMimeType;
            }

            $client = new Client(['http_errors' => false]);
            $response = $client->request('GET', $url, $options);
            $statusCode = $response->getStatusCode();

            if (400 <= $statusCode) {
                return new UpstreamFetchResult(null, $statusCode, false);
            }

            return new UpstreamFetchResult((string)$response->getBody(), $statusCode, false);
        } catch (GuzzleException) {
            return new UpstreamFetchResult(null, null, true);
        }
    }

    /**
     * @param array<string, mixed> $httpConfig
     * @return array<string, mixed>
     */
    public static function guzzleOptionsFromHttpConfig(array $httpConfig): array
    {
        $options = [];

        if (!empty($httpConfig['proxy']) && is_string($httpConfig['proxy'])) {
            $options['proxy'] = $httpConfig['proxy'];
        }

        if (isset($httpConfig['timeout'])) {
            $options['timeout'] = (float)$httpConfig['timeout'];
        }

        if (isset($httpConfig['connect_timeout'])) {
            $options['connect_timeout'] = (float)$httpConfig['connect_timeout'];
        }

        if (array_key_exists('allow_redirects', $httpConfig)) {
            $options['allow_redirects'] = $httpConfig['allow_redirects'];
        }

        if (!empty($httpConfig['headers']) && is_array($httpConfig['headers'])) {
            $headers = [];

            foreach ($httpConfig['headers'] as $name => $value) {
                if (is_string($name) && (is_string($value) || is_numeric($value))) {
                    $headers[$name] = (string)$value;
                }
            }

            if ($headers !== []) {
                $options['headers'] = $headers;
            }
        }

        return $options;
    }
}
