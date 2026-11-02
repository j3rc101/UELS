<?php
declare(strict_types=1);

use App\Core\Env;

define('BASE_PATH', dirname(__DIR__));

// Minimal PSR-4 style autoloader: App\Core\Foo => src/Core/Foo.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Env::load(BASE_PATH . '/.env');
date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'Asia/Manila'));

ini_set('display_errors', '0');
error_reporting(E_ALL);
