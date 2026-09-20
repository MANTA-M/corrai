<?php

// Basic PSR-4 autoloader
spl_autoload_register(function ($class) {

    $prefix = 'Corrai\\';
    $base_dir = __DIR__ . '/Corrai/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    } else {
        error_log("Not found class file " . $file);
    }
});

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendorAutoload))
    include_once($vendorAutoload);
