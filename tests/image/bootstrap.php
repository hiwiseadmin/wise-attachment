<?php

declare(strict_types=1);


$autoloadPaths = [
    __DIR__ . '/../../../../../vendor/autoload.php',
    __DIR__ . '/../../../../../../../vendor/autoload.php',
];

$found = false;
foreach ($autoloadPaths as $path) {
    $normalized = realpath($path);
    if ($normalized !== false && file_exists($normalized)) {
        require $normalized;
        $found = true;
        break;
    }
}

if (!class_exists(\wise\image\ImageManager::class, false)) {
    spl_autoload_register(function (string $class) {
        $prefix = 'wise\\image\\';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relativeClass = substr($class, $len);
        $file = __DIR__ . '/../../src/image/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });
}

if (!$found) {
    fwrite(STDERR, "Warning: Composer autoloader not found. Using manual PSR-4 fallback.\n");
}
