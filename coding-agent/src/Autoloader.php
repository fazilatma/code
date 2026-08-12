<?php

declare(strict_types=1);

namespace Bonsai;

final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Bonsai\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = __DIR__ . '/' . $relative . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}
