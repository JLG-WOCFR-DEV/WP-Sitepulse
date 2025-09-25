<?php
declare(strict_types=1);

define('ABSPATH', __DIR__);

if (!function_exists('add_action')) {
    function add_action(...$args) {}
}

if (!function_exists('add_submenu_page')) {
    function add_submenu_page(...$args) {}
}

if (!function_exists('current_user_can')) {
    function current_user_can(...$args) { return true; }
}

if (!function_exists('wp_die')) {
    function wp_die(...$args) { throw new RuntimeException('wp_die called'); }
}

if (!function_exists('__')) {
    function __($text, $domain = null) { return $text; }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = null) {
        return $number > 1 ? $plural : $single;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null) { return $text; }
}

if (!function_exists('esc_html')) {
    function esc_html($text) { return $text; }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) { return $text; }
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number) { return (string) $number; }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(...$args) {}
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(...$args) { return true; }
}

if (!function_exists('wp_delete_post')) {
    function wp_delete_post(...$args) { return true; }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return false; }
}

if (!function_exists('disabled')) {
    function disabled(...$args) { return ''; }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        return $value;
    }
}

if (!function_exists('is_multisite')) {
    $GLOBALS['sitepulse_is_multisite'] = false;

    function is_multisite() {
        return !empty($GLOBALS['sitepulse_is_multisite']);
    }
}

if (!function_exists('get_current_network_id')) {
    function get_current_network_id() {
        return 1;
    }
}

$GLOBALS['sitepulse_cache_log'] = [];

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') {
        $GLOBALS['sitepulse_cache_log'][] = [
            'group' => $group,
            'key'   => $key,
        ];

        return true;
    }
}

require_once dirname(__DIR__) . '/modules/database_optimizer.php';

class Sitepulse_Fake_WPDB {
    public $options = 'options';
    public $sitemeta = 'sitemeta';
    public $siteid = 1;

    private $data = [
        'options'  => [],
        'sitemeta' => [],
    ];

    private $prepared = [];

    public function esc_like($text) {
        return addcslashes($text, '_%\\');
    }

    public function prepare($query, $args = []) {
        if (!is_array($args)) {
            $args = array_slice(func_get_args(), 1);
        }

        $token = 'stmt_' . count($this->prepared);
        $this->prepared[$token] = [
            'query' => $query,
            'args'  => $args,
        ];

        return $token;
    }

    public function get_col($token) {
        if (!isset($this->prepared[$token])) {
            return [];
        }

        $statement = $this->prepared[$token];
        unset($this->prepared[$token]);

        if (!preg_match('/FROM\s+(\S+)/i', $statement['query'], $matches)) {
            return [];
        }

        $table = $matches[1];
        $like_prefix = $this->normalize_like_prefix($statement['args'][0] ?? '');
        $timestamp = isset($statement['args'][1]) ? (int) $statement['args'][1] : PHP_INT_MAX;
        $site_id = isset($statement['args'][2]) ? (int) $statement['args'][2] : null;

        $column = ($table === $this->options) ? 'option_name' : 'meta_key';
        $value_column = ($table === $this->options) ? 'option_value' : 'meta_value';

        $results = [];

        foreach ($this->data[$table] as $row) {
            if ($like_prefix !== '' && strpos($row[$column], $like_prefix) !== 0) {
                continue;
            }

            if ((int) $row[$value_column] >= $timestamp) {
                continue;
            }

            if ($table === $this->sitemeta && $site_id !== null && (int) $row['site_id'] !== $site_id) {
                continue;
            }

            $results[] = $row[$column];
        }

        return $results;
    }

    public function delete($table, $where, $where_format = null) {
        foreach ($this->data[$table] as $index => $row) {
            $matches = true;

            foreach ($where as $column => $value) {
                if (!array_key_exists($column, $row) || (string) $row[$column] !== (string) $value) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                unset($this->data[$table][$index]);
                return 1;
            }
        }

        return 0;
    }

    public function add_option_row($name, $value) {
        $this->data[$this->options][] = [
            'option_name'  => $name,
            'option_value' => $value,
        ];
    }

    public function add_sitemeta_row($site_id, $name, $value) {
        $this->data[$this->sitemeta][] = [
            'site_id'   => (int) $site_id,
            'meta_key'  => $name,
            'meta_value'=> $value,
        ];
    }

    public function get_option_value($name) {
        foreach ($this->data[$this->options] as $row) {
            if ($row['option_name'] === $name) {
                return $row['option_value'];
            }
        }

        return null;
    }

    public function get_sitemeta_value($site_id, $name) {
        foreach ($this->data[$this->sitemeta] as $row) {
            if ((int) $row['site_id'] === (int) $site_id && $row['meta_key'] === $name) {
                return $row['meta_value'];
            }
        }

        return null;
    }

    private function normalize_like_prefix($value) {
        if ($value === '') {
            return '';
        }

        if (substr($value, -1) === '%') {
            $value = substr($value, 0, -1);
        }

        $value = str_replace('\\_', '_', $value);
        $value = str_replace('\\\\', '\\', $value);

        return $value;
    }
}

function sitepulse_assert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function get_transient($key) {
    global $wpdb;

    if (!($wpdb instanceof Sitepulse_Fake_WPDB)) {
        return false;
    }

    $timeout = $wpdb->get_option_value('_transient_timeout_' . $key);

    if ($timeout !== null && (int) $timeout < time()) {
        return false;
    }

    $value = $wpdb->get_option_value('_transient_' . $key);

    return $value !== null ? $value : false;
}

$now = time();

// Scenario 1: single site options table.
$GLOBALS['sitepulse_is_multisite'] = false;
$wpdb = new Sitepulse_Fake_WPDB();
$GLOBALS['wpdb'] = $wpdb;
$GLOBALS['sitepulse_cache_log'] = [];

$wpdb->add_option_row('_transient_timeout_expired', $now - 10);
$wpdb->add_option_row('_transient_expired', 'expired-value');
$wpdb->add_option_row('_transient_timeout_active', $now + 1000);
$wpdb->add_option_row('_transient_active', 'active-value');
$wpdb->add_option_row('_site_transient_timeout_site', $now - 10);
$wpdb->add_option_row('_site_transient_site', 'site-value');

$cleaned = sitepulse_delete_expired_transients_fallback($wpdb);

sitepulse_assert($cleaned === 2, 'Expected two expired transients to be cleaned.');
sitepulse_assert($wpdb->get_option_value('_transient_expired') === null, 'Transient value should be removed.');
sitepulse_assert($wpdb->get_option_value('_transient_timeout_expired') === null, 'Transient timeout should be removed.');
sitepulse_assert($wpdb->get_option_value('_site_transient_site') === null, 'Site transient value should be removed.');
sitepulse_assert(get_transient('expired') === false, 'get_transient must return false for expired entries.');
sitepulse_assert($wpdb->get_option_value('_transient_active') !== null, 'Active transient should remain untouched.');

$expected_cache_keys = [
    ['group' => 'options', 'key' => '_transient_timeout_expired'],
    ['group' => 'options', 'key' => '_transient_expired'],
    ['group' => 'options', 'key' => '_site_transient_timeout_site'],
    ['group' => 'options', 'key' => '_site_transient_site'],
];

foreach ($expected_cache_keys as $expected) {
    sitepulse_assert(in_array($expected, $GLOBALS['sitepulse_cache_log'], true), 'Cache flush missing for ' . $expected['key']);
}

// Scenario 2: multisite sitemeta table.
$GLOBALS['sitepulse_is_multisite'] = true;
$wpdb_ms = new Sitepulse_Fake_WPDB();
$wpdb_ms->add_sitemeta_row(1, '_site_transient_timeout_network', $now - 20);
$wpdb_ms->add_sitemeta_row(1, '_site_transient_network', 'network-value');
$GLOBALS['wpdb'] = $wpdb_ms;
$GLOBALS['sitepulse_cache_log'] = [];

$cleaned_ms = sitepulse_delete_expired_transients_fallback($wpdb_ms);

sitepulse_assert($cleaned_ms === 1, 'Expected one multisite transient to be cleaned.');
sitepulse_assert($wpdb_ms->get_sitemeta_value(1, '_site_transient_network') === null, 'Multisite transient value should be removed.');
sitepulse_assert(in_array(['group' => 'site-options', 'key' => '1:_site_transient_timeout_network'], $GLOBALS['sitepulse_cache_log'], true), 'Cache flush missing for multisite timeout.');
sitepulse_assert(in_array(['group' => 'site-options', 'key' => '1:_site_transient_network'], $GLOBALS['sitepulse_cache_log'], true), 'Cache flush missing for multisite value.');

echo "All transient fallback assertions passed." . PHP_EOL;
