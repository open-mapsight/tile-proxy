<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use OpenMapsight\TileProxy\FileLock;

[$script, $mode, $path, $timeout, $holdMicroseconds] = $argv;
if ($mode === 'wait') {
    echo "READY\n";
    fflush(STDOUT);
    fgets(STDIN);
}

$lock = FileLock::acquire($path, (float)$timeout);
echo $lock === null ? "TIMEOUT\n" : "LOCKED\n";
fflush(STDOUT);
if ($lock !== null) {
    if ($mode === 'hold') {
        usleep((int)$holdMicroseconds);
    } else {
        fgets(STDIN);
    }
    $lock->release();
}
