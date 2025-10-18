<?php
// Provide stubs for WordPress functions that PHPStan needs to know about during analysis.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Load any available WordPress test stubs if present.
$stubPaths = [
    __DIR__ . '/tests/phpstan-stubs.php',
    __DIR__ . '/sitepulse_FR/tests/phpstan-stubs.php',
];

foreach ($stubPaths as $stubPath) {
    if (file_exists($stubPath)) {
        require_once $stubPath;
    }
}
