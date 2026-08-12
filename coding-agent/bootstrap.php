<?php

declare(strict_types=1);

require __DIR__ . '/src/Autoloader.php';

Bonsai\Autoloader::register();

$config = require __DIR__ . '/config.php';

foreach (['workspace', 'sessions'] as $dirKey) {
    $dir = $config[$dirKey];
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Unable to create directory: {$dir}");
    }
}

return $config;
