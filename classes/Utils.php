<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use JsonException;
use RuntimeException;

class Utils
{
    public const SUPPORTED_IMAGE_MIME_TYPES = [
        'image/bmp',
        'image/x-bmp',
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * @throws JsonException
     */
    public static function parseJsoncString(string $str): array
    {
        // support jsonc; remove comments
        // https://www.php.net/manual/en/function.json-decode.php#112735
        $json = preg_replace(
            '#(/\*([^*]|[\r\n]|(\*+([^*/]|[\r\n])))*\*+/)|([\s\t]//.*)|(^//.*)#',
            '',
            $str
        );

        if (!is_string($json)) {
            throw new RuntimeException('Could not strip JSONC comments');
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Configuration JSON must be an object');
        }

        return $decoded;
    }

    public static function assertImageMimeType(string $mimeType): void
    {
        $found = in_array(
            $mimeType,
            Utils::SUPPORTED_IMAGE_MIME_TYPES,
            true
        );

        if (!$found) {
            throw new RuntimeException('Unsupported image mime type: "' . $mimeType . '"');
        }
    }

    public static function bytesToImage(string $data)
    {
        $img = imageCreateFromString($data);
        imagePaletteToTrueColor($img);
        imageSaveAlpha($img, true);
        imageAlphaBlending($img, true);
        return $img;
    }

    public static function imageToBytes(string $mimeType, $img): string
    {
        ob_start();
        try {
            switch ($mimeType) {
                case 'image/bmp':
                case 'image/x-bmp':
                    imageBmp($img);
                    break;

                case 'image/gif':
                    imageGif($img);
                    break;

                case 'image/jpeg':
                    imageJpeg($img);
                    break;


                case 'image/png':
                    imagePng($img);
                    break;

                case 'image/webp':
                    imageWebP($img);
                    break;

                default:
                    throw new RuntimeException('Unsupported image mime type: "' . $mimeType . '"');
            }
            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public static function writeToFile(string $path, string $data): void
    {
        self::mkdirp(dirname($path));
        @file_put_contents($path, $data);
    }

    public static function mkdirp($pathDir): void
    {
        if (!@mkdir($pathDir, 0777, true) && !is_dir($pathDir)) {
            throw new RuntimeException('Directory "' . $pathDir . '" was not created');
        }
    }
}
