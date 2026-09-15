<?php
/**
 * Editor canvas helpers for WordPress 7.1 iframed block editors.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('sitepulse_is_block_editor_preview_context')) {
    /**
     * Detect Gutenberg canvas / block-renderer requests where front JS must not run.
     *
     * @return bool
     */
    function sitepulse_is_block_editor_preview_context() {
        if (function_exists('wp_is_block_editor') && wp_is_block_editor()) {
            return true;
        }

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && !empty($screen->is_block_editor)) {
                return true;
            }
        }

        if (isset($_GET['canvas']) && 'edit' === $_GET['canvas']) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return true;
        }

        $context = '';

        if (isset($_REQUEST['context'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $raw     = $_REQUEST['context']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $raw     = function_exists('wp_unslash') ? wp_unslash($raw) : $raw;
            $context = function_exists('sanitize_key') ? sanitize_key($raw) : strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $raw));
        }

        $route = '';

        if (isset($GLOBALS['wp']) && is_object($GLOBALS['wp']) && isset($GLOBALS['wp']->query_vars['rest_route'])) {
            $route = (string) $GLOBALS['wp']->query_vars['rest_route'];
        } elseif (isset($_SERVER['REQUEST_URI'])) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $route = (string) $_SERVER['REQUEST_URI'];
        }

        $is_rest = (defined('REST_REQUEST') && REST_REQUEST)
            || ($route !== '' && false !== strpos($route, '/wp-json/'));

        if ($is_rest && 'edit' === $context) {
            return true;
        }

        if ($route !== '' && false !== strpos($route, 'block-renderer')) {
            return true;
        }

        return false;
    }
}

if (!function_exists('sitepulse_should_enqueue_frontend_script')) {
    /**
     * Whether interactive front scripts (slideshow, RUM) should be enqueued.
     *
     * @return bool
     */
    function sitepulse_should_enqueue_frontend_script() {
        if (sitepulse_is_block_editor_preview_context()) {
            return false;
        }

        if (function_exists('is_admin') && is_admin()) {
            return false;
        }

        return true;
    }
}

if (!function_exists('sitepulse_enqueue_editor_canvas_guard')) {
    /**
     * Copy the editor-mode flag into the WP 6.3+/7.1 iframed canvas.
     *
     * `enqueue_block_assets` runs in the iframe (and on the front). The flag must
     * stay off on the public site.
     *
     * @return void
     */
    function sitepulse_enqueue_editor_canvas_guard() {
        if (!function_exists('is_admin') || !is_admin()) {
            return;
        }

        if (!function_exists('wp_register_script') || !function_exists('wp_enqueue_script') || !function_exists('wp_add_inline_script')) {
            return;
        }

        $version = defined('SITEPULSE_VERSION') ? SITEPULSE_VERSION : false;

        wp_register_script(
            'sitepulse-editor-canvas-guard',
            false,
            [],
            $version,
            true
        );
        wp_enqueue_script('sitepulse-editor-canvas-guard');
        wp_add_inline_script(
            'sitepulse-editor-canvas-guard',
            'window.SITEPULSE_IS_EDITOR = true;',
            'before'
        );

        if (function_exists('wp_enqueue_style') && function_exists('wp_style_is') && wp_style_is('sitepulse-dashboard-preview-editor-style', 'registered')) {
            wp_enqueue_style('sitepulse-dashboard-preview-editor-style');
        }
    }
}

if (function_exists('add_action')) {
    add_action('enqueue_block_assets', 'sitepulse_enqueue_editor_canvas_guard');
}
