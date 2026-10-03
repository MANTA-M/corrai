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

    if (!file_exists($file)) {
        $short = basename(str_replace('\\', '/', $relative_class));
        if (str_ends_with($short, 'Task')) {
            $stem = substr($short, 0, -strlen('Task'));
            $dir = dirname($file);
            $matches = glob($dir . '/Task*' . $stem . '.php') ?: [];
            sort($matches);
            if ($matches !== []) {
                $file = $matches[0];
            }
        }
    }

    if (file_exists($file)) {
        require $file;
    } else {
        error_log("Not found class file " . $file);
    }
});

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendorAutoload))
    include_once($vendorAutoload);
