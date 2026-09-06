<?php declare(strict_types=1);

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are not installed. Run:\n\n"
        . "  docker-compose exec app composer install\n\n");
    exit(1);
}
require_once $autoload;
