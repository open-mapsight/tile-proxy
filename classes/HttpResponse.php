<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use Throwable;

class HttpResponse
{
    public function __construct(
        public readonly ?string $body,
        public readonly ?string $mimeType,
        public readonly ?int    $cacheBrowserTtl,
        public readonly ?int    $cacheMTime,
        public readonly bool    $notModified = false,
    )
    {
    }

    public function isNotFound(): bool
    {
        return $this->mimeType === null;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    public static function sendRequest(array $cfg, callable $buildResponse): void
    {
        Log::configureFromConfig($cfg);

        try {
            self::send($buildResponse());
        } catch (UserException $e) {
            header('HTTP/1.0 400 Bad Request', true, 400);
            echo $e->getMessage();
        } catch (Throwable $e) {
            header('HTTP/1.0 500 Internal Server Error', true, 500);
            Log::error('Request handler failed', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function send(self $response): void
    {
        if ($response->isNotFound()) {
            header('HTTP/1.0 404 Not Found', true, 404);
            echo 'tile not found';
            return;
        }

        assert($response->mimeType !== null);

        $time = time();

        header('Content-Type: ' . $response->mimeType);

        if ($response->cacheBrowserTtl !== null) {
            header('Expires: ' . gmdate('D, d M Y H:i:s', $time + $response->cacheBrowserTtl) . ' GMT');
            header('Cache-Control: public, max-age=' . $response->cacheBrowserTtl);
        }

        if ($response->cacheMTime !== null) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $response->cacheMTime) . ' GMT');
        }

        if ($response->notModified) {
            header('HTTP/1.1 304 Not Modified');
            return;
        }

        assert($response->body !== null);
        header('Content-Length: ' . strlen($response->body));
        echo $response->body;
    }
}
