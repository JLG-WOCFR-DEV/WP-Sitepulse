<?php
/**
 * Tests for the transient fallback cleanup logic.
 */

require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/database_optimizer.php';

class Sitepulse_Fake_WPDB {
    public $options = 'options';
    public $sitemeta = 'sitemeta';
    public $siteid = 1;

    private $data = [
        'options'  => [],
        'sitemeta' => [],
    ];

    private $prepared = [];
    private $prepared_log = [];

    public function esc_like($text) {
        return addcslashes($text, '_%\\');
    }

    public function prepare($query, $args = []) {
        if (!is_array($args)) {
            $args = array_slice(func_get_args(), 1);
        }

        $prepared_query = $this->build_prepared_query($query, $args);
        $statement = [
            'query'    => $query,
            'args'     => $args,
            'prepared' => $prepared_query,
        ];

        $this->prepared[$prepared_query] = $statement;
        $this->prepared_log[] = $statement;

        return $prepared_query;
    }

    public function get_prepared_log() {
        return $this->prepared_log;
    }

    public function reset_prepared_log() {
        $this->prepared_log = [];
    }

    public function get_col($prepared_query) {
        if (!isset($this->prepared[$prepared_query])) {
            return [];
        }

        $statement = $this->prepared[$prepared_query];
        unset($this->prepared[$prepared_query]);

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

        $value = str_replace('\_', '_', $value);
        $value = str_replace('\\', '\', $value);

        return $value;
    }

    private function build_prepared_query($query, $args) {
        if (empty($args)) {
            return $query;
        }

        $prepared = '';
        $offset = 0;
        $arg_index = 0;

        while (preg_match('/%(?:%|(?:\d+\$)?[dsf])/i', $query, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $placeholder = $matches[0][0];
            $position = $matches[0][1];

            $prepared .= substr($query, $offset, $position - $offset);
            $offset = $position + strlen($placeholder);

            if ($placeholder === '%%') {
                $prepared .= '%';
                continue;
            }

            if (!array_key_exists($arg_index, $args)) {
                break;
            }

            $prepared .= $this->format_placeholder($placeholder, $args[$arg_index]);
            $arg_index++;
        }

        $prepared .= substr($query, $offset);

        return $prepared;
    }

    private function format_placeholder($placeholder, $value) {
        switch ($placeholder) {
            case '%d':
                return (string) (int) $value;
            case '%f':
                return (string) (float) $value;
            case '%s':
            default:
                return "'" . addslashes((string) $value) . "'";
        }
    }
}

class Sitepulse_Transient_Fallback_Test extends WP_UnitTestCase {
    protected function set_up(): void {
        parent::set_up();
        $GLOBALS['sitepulse_cache_log'] = [];
    }

    protected function tear_down(): void {
        unset($GLOBALS['sitepulse_cache_log']);
        parent::tear_down();
    }

    public function test_single_site_transient_cleanup_removes_expired_entries() {
        $now = time();
        $wpdb = new Sitepulse_Fake_WPDB();
        $previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->add_option_row('_transient_timeout_expired', $now - 10);
        $wpdb->add_option_row('_transient_expired', 'expired-value');
        $wpdb->add_option_row('_transient_timeout_active', $now + 1000);
        $wpdb->add_option_row('_transient_active', 'active-value');
        $wpdb->add_option_row('_transient_timeout_invalid', 'not-a-number');
        $wpdb->add_option_row('_transient_invalid', 'invalid-value');
        $wpdb->add_option_row('_site_transient_timeout_site', $now - 10);
        $wpdb->add_option_row('_site_transient_site', 'site-value');

        $object_cache = $GLOBALS['wp_object_cache'];
        $spy_cache = new class($object_cache) {
            public $deletions = [];
            private $previous;

            public function __construct($previous) {
                $this->previous = $previous;
            }

            public function delete($key, $group = '', $force = false, $found = null) {
                $this->deletions[] = ['group' => $group, 'key' => $key];
                return $this->previous ? $this->previous->delete($key, $group, $force, $found) : true;
            }
        };
        $GLOBALS['wp_object_cache'] = $spy_cache;

        $cleaned = sitepulse_delete_expired_transients_fallback($wpdb);

        $this->assertSame(3, $cleaned, 'Expected three expired transients to be cleaned, including malformed ones.');
        $this->assertNull($wpdb->get_option_value('_transient_expired'));
        $this->assertNull($wpdb->get_option_value('_transient_timeout_expired'));
        $this->assertNull($wpdb->get_option_value('_transient_invalid'));
        $this->assertNull($wpdb->get_option_value('_transient_timeout_invalid'));
        $this->assertNull($wpdb->get_option_value('_site_transient_site'));
        $this->assertNotNull($wpdb->get_option_value('_transient_active'));

        $prepared_statements = $wpdb->get_prepared_log();
        $options_query = "SELECT option_name FROM options WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_value ASC LIMIT %d";
        $this->assertContains($options_query, wp_list_pluck($prepared_statements, 'query'));

        $expected_prepared = sprintf(
            "SELECT option_name FROM options WHERE option_name LIKE '%s' AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_value ASC LIMIT %d",
            addslashes($wpdb->esc_like('_transient_timeout_') . '%'),
            (int) $now,
            100
        );

        $matched_prepared = null;

        foreach ($prepared_statements as $statement) {
            if ($statement['query'] === $options_query) {
                $matched_prepared = $statement['prepared'];
                break;
            }
        }

        $this->assertSame($expected_prepared, $matched_prepared, 'Prepared SQL for options cleanup does not match expectation.');

        $found_numeric_cast = false;

        foreach ($prepared_statements as $statement) {
            if (strpos($statement['query'], 'CAST(option_value AS UNSIGNED) < %d') !== false) {
                $this->assertIsInt($statement['args'][1]);
                $found_numeric_cast = true;
                break;
            }
        }

        $this->assertTrue($found_numeric_cast, 'Expected transient cleanup query to cast option_value as UNSIGNED.');

        $expected_cache_keys = [
            ['group' => 'options', 'key' => '_transient_timeout_expired'],
            ['group' => 'options', 'key' => '_transient_expired'],
            ['group' => 'options', 'key' => '_transient_timeout_invalid'],
            ['group' => 'options', 'key' => '_transient_invalid'],
            ['group' => 'options', 'key' => '_site_transient_timeout_site'],
            ['group' => 'options', 'key' => '_site_transient_site'],
        ];

        $this->assertEquals($expected_cache_keys, $spy_cache->deletions);

        $GLOBALS['wp_object_cache'] = $object_cache;
        $GLOBALS['wpdb'] = $previous_wpdb;
    }

    public function test_site_meta_cleanup_targets_current_network() {
        $now = time();
        $wpdb = new Sitepulse_Fake_WPDB();
        $previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->siteid = 7;

        $wpdb->add_sitemeta_row(7, '_site_transient_timeout_network', $now - 20);
        $wpdb->add_sitemeta_row(7, '_site_transient_network', 'network-value');
        $wpdb->add_sitemeta_row(3, '_site_transient_timeout_foreign', $now - 50);
        $wpdb->add_sitemeta_row(3, '_site_transient_foreign', 'should-stay');

        $object_cache = $GLOBALS['wp_object_cache'];
        $spy_cache = new class($object_cache) {
            public $deletions = [];
            private $previous;

            public function __construct($previous) {
                $this->previous = $previous;
            }

            public function delete($key, $group = '', $force = false, $found = null) {
                $this->deletions[] = ['group' => $group, 'key' => $key];
                return $this->previous ? $this->previous->delete($key, $group, $force, $found) : true;
            }
        };
        $GLOBALS['wp_object_cache'] = $spy_cache;

        $source = [
            'timeout_prefix' => '_site_transient_timeout_',
            'value_prefix'   => '_site_transient_',
            'table'          => $wpdb->sitemeta,
            'key_column'     => 'meta_key',
            'value_column'   => 'meta_value',
            'cache_group'    => 'site-options',
            'site_id'        => $wpdb->siteid,
        ];

        $purged = sitepulse_cleanup_transient_source($wpdb, $source, $now);

        $this->assertSame(1, $purged, 'Only the current network transient should be removed.');
        $this->assertNull($wpdb->get_sitemeta_value(7, '_site_transient_network'));
        $this->assertNotNull($wpdb->get_sitemeta_value(3, '_site_transient_foreign'));

        $this->assertEquals([
            ['group' => 'site-options', 'key' => $wpdb->siteid . ':_site_transient_timeout_network'],
            ['group' => 'site-options', 'key' => $wpdb->siteid . ':_site_transient_network'],
        ], $spy_cache->deletions);

        $GLOBALS['wp_object_cache'] = $object_cache;
        $GLOBALS['wpdb'] = $previous_wpdb;
    }
}
