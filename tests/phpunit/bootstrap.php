<?php
/**
 * PHPUnit bootstrap file for SitePulse.
 */

$_tests_dir = getenv('WP_TESTS_DIR');

if (!$_tests_dir) {
    $_tests_dir = rtrim(sys_get_temp_dir(), "/\\") . '/wordpress-tests-lib';
}

if (!file_exists($_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, sprintf("Could not find WordPress tests in %s.\n", $_tests_dir));
    exit(1);
}

require_once $_tests_dir . '/includes/functions.php';
require_once __DIR__ . '/includes/stubs.php';
require $_tests_dir . '/includes/bootstrap.php';
