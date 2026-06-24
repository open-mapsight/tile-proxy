<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

class Log
{
    /** @var object|null Logger with a `warning(string $message, array $context = []): void` method (PSR-3 compatible). */
    private static ?object $logger = null;

    private static bool $useErrorLog = false;

    /**
     * @param object|null $logger PSR-3 compatible logger (for example Monolog).
     */
    public static function setLogger(?object $logger): void
    {
        self::$logger = $logger;
    }

    public static function enableErrorLog(bool $enabled = true): void
    {
        self::$useErrorLog = $enabled;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    public static function configureFromConfig(array $cfg): void
    {
        self::$useErrorLog = !empty($cfg['logUpstreamErrors']);
    }

    public static function reset(): void
    {
        self::$logger = null;
        self::$useErrorLog = false;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = []): void
    {
        if (self::$logger !== null && method_exists(self::$logger, 'warning')) {
            self::$logger->warning($message, $context);

            return;
        }

        if (self::$useErrorLog) {
            self::writeErrorLog('WARNING', $message, $context);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function writeErrorLog(string $level, string $message, array $context): void
    {
        $line = '[mapsight-tile-proxy][' . $level . '] ' . $message;

        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        error_log($line);
    }
}
