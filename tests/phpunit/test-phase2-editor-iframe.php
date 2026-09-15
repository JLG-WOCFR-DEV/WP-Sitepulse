<?php
/**
 * Phase 2: front JS must not run in the WP 7.1 iframed editor.
 *
 * @package SitePulse
 */

class Sitepulse_Phase2_Editor_Iframe_Test extends PHPUnit\Framework\TestCase {
    private function plugin_root() {
        return dirname(__DIR__, 2) . '/sitepulse_FR';
    }

    private function read($relative) {
        $path = $this->plugin_root() . '/' . ltrim($relative, '/');
        $this->assertFileExists($path, $relative . ' should exist.');

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        return $contents;
    }

    protected function setUp(): void {
        parent::setUp();

        unset($_GET['canvas'], $_REQUEST['context'], $_SERVER['REQUEST_URI']);

        if (defined('REST_REQUEST') && REST_REQUEST) {
            // Constant cannot be undefined; tests that need REST_REQUEST set it once.
        }
    }

    public function test_editor_canvas_helper_file_exists() {
        $this->assertFileExists($this->plugin_root() . '/includes/editor-canvas.php');
    }

    public function test_front_scripts_skip_editor_canvas_in_javascript() {
        $slideshow = $this->read('modules/js/sitepulse-article-slideshow.js');
        $rum = $this->read('modules/js/sitepulse-rum.js');

        foreach ([$slideshow, $rum] as $source) {
            $this->assertStringContainsString('sitepulseIsEditorCanvas', $source);
            $this->assertStringContainsString('block-editor-iframe__body', $source);
            $this->assertStringContainsString('editor-canvas', $source);
            $this->assertStringContainsString('SITEPULSE_IS_EDITOR', $source);
        }
    }

    public function test_php_enqueue_paths_call_the_editor_guard() {
        $plugin = $this->read('sitepulse.php');
        $rum = $this->read('modules/speed-analyzer/rum.php');
        $editor = $this->read('includes/editor-canvas.php');

        $this->assertStringContainsString('includes/editor-canvas.php', $plugin);
        $this->assertStringContainsString('sitepulse_should_enqueue_frontend_script', $plugin);
        $this->assertStringContainsString('sitepulse_should_enqueue_frontend_script', $rum);
        $this->assertStringContainsString('enqueue_block_assets', $editor);
        $this->assertStringContainsString('SITEPULSE_IS_EDITOR', $editor);
    }

    public function test_is_block_editor_preview_context_detects_canvas_edit() {
        $this->load_editor_helpers();

        $_GET['canvas'] = 'edit';
        $this->assertTrue(sitepulse_is_block_editor_preview_context());
        $this->assertFalse(sitepulse_should_enqueue_frontend_script());
    }

    public function test_is_block_editor_preview_context_detects_block_renderer_rest() {
        $this->load_editor_helpers();

        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/block-renderer/sitepulse/dashboard-preview';
        $_REQUEST['context'] = 'edit';

        $this->assertTrue(sitepulse_is_block_editor_preview_context());
        $this->assertFalse(sitepulse_should_enqueue_frontend_script());
    }

    public function test_front_context_still_allows_frontend_scripts() {
        $this->load_editor_helpers();

        unset($_GET['canvas'], $_REQUEST['context'], $_SERVER['REQUEST_URI']);
        $GLOBALS['sitepulse_test_is_admin'] = false;
        $GLOBALS['sitepulse_test_is_block_editor'] = false;

        $this->assertFalse(sitepulse_is_block_editor_preview_context());
        $this->assertTrue(sitepulse_should_enqueue_frontend_script());
    }

    private function load_editor_helpers() {
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }

        $GLOBALS['sitepulse_test_is_admin'] = false;
        $GLOBALS['sitepulse_test_is_block_editor'] = false;

        if (!function_exists('is_admin')) {
            function is_admin() {
                return !empty($GLOBALS['sitepulse_test_is_admin']);
            }
        }

        if (!function_exists('wp_is_block_editor')) {
            function wp_is_block_editor() {
                return !empty($GLOBALS['sitepulse_test_is_block_editor']);
            }
        }

        if (!function_exists('sanitize_key')) {
            function sanitize_key($key) {
                $key = strtolower((string) $key);
                return preg_replace('/[^a-z0-9_\-]/', '', $key);
            }
        }

        if (!function_exists('wp_unslash')) {
            function wp_unslash($value) {
                return is_string($value) ? stripslashes($value) : $value;
            }
        }

        require_once $this->plugin_root() . '/includes/editor-canvas.php';
    }
}
