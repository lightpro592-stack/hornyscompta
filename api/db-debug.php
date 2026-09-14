<?php
require __DIR__ . '/../config.php';

header('Content-Type: text/plain; charset=utf-8');

echo 'DB_DRIVER=' . DB_DRIVER . PHP_EOL;
echo 'DB_HOST=' . DB_HOST . PHP_EOL;
echo 'DB_PORT=' . DB_PORT . PHP_EOL;
echo 'DB_NAME=' . DB_NAME . PHP_EOL;
echo 'PDO_DRIVERS=' . implode(',', PDO::getAvailableDrivers()) . PHP_EOL;

try {
    db();
    echo 'DB_OK=1' . PHP_EOL;
} catch (Throwable $exception) {
    echo 'DB_OK=0' . PHP_EOL;
    echo 'ERROR=' . $exception->getMessage() . PHP_EOL;
}
