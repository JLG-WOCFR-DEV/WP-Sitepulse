<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('sitepulse_define_constant')) {
    /**
     * Defines a constant when it has not yet been set.
     *
     * @param string $name  Constant name.
     * @param mixed  $value Constant value.
     *
     * @return void
     */
    function sitepulse_define_constant($name, $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
}
