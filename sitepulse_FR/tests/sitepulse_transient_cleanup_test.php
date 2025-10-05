<?php
declare(strict_types=1);

define('ABSPATH', __DIR__);

if (!class_exists('wpdb')) {
    class wpdb {}
}

$GLOBALS['sitepulse_is_multisite'] = false;
$GLOBALS['sitepulse_deleted_transients'] = [];
$GLOBALS['sitepulse_deleted_site_transients'] = [];

class Sitepulse_Test_WPDB extends wpdb
{
    public $options = 'wp_options';
    public $sitemeta = 'wp_sitemeta';

    private $queries = [];

    public $data = [
        'options'  => [],
        'sitemeta' => [],
    ];

    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%\\');
    }

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $token = 'stmt_' . count($this->queries);

        $this->queries[$token] = [
            'query' => (string) $query,
            'args'  => $args,
        ];

        return $token;
    }

    public function get_col($token)
    {
        if (!isset($this->queries[$token])) {
            return [];
        }

        $statement = $this->queries[$token];
        unset($this->queries[$token]);

        $table = (strpos($statement['query'], $this->sitemeta) !== false) ? 'sitemeta' : 'options';
        $column = ($table === 'options') ? 'option_name' : 'meta_key';
        $like_1 = $this->normalize_like($statement['args'][0] ?? '');
        $like_2 = $this->normalize_like($statement['args'][1] ?? '');

        $results = [];

        foreach ($this->data[$table] as $row) {
            $value = $row[$column] ?? '';

            if ($like_1 !== '' && strpos($value, $like_1) === 0) {
                $results[] = $value;
                continue;
            }

            if ($like_2 !== '' && strpos($value, $like_2) === 0) {
                $results[] = $value;
            }
        }

        return $results;
    }

    public function set_option_rows(array $rows)
    {
        $this->data['options'] = array_values($rows);
    }

    public function set_sitemeta_rows(array $rows)
    {
        $this->data['sitemeta'] = array_values($rows);
    }

    public function has_option($name)
    {
        foreach ($this->data['options'] as $row) {
            if (($row['option_name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    public function has_meta($key)
    {
        foreach ($this->data['sitemeta'] as $row) {
            if (($row['meta_key'] ?? null) === $key) {
                return true;
            }
        }

        return false;
    }

    public function delete_transient_rows($key)
    {
        $targets = [
            '_transient_' . $key,
            '_transient_timeout_' . $key,
        ];

        $this->data['options'] = array_values(array_filter(
            $this->data['options'],
            function ($row) use ($targets) {
                return !in_array($row['option_name'] ?? '', $targets, true);
            }
        ));
    }

    public function delete_site_transient_rows($key)
    {
        $targets = [
            '_site_transient_' . $key,
            '_site_transient_timeout_' . $key,
        ];

        $this->data['sitemeta'] = array_values(array_filter(
            $this->data['sitemeta'],
            function ($row) use ($targets) {
                return !in_array($row['meta_key'] ?? '', $targets, true);
            }
        ));
    }

    private function normalize_like($pattern)
    {
        $pattern = (string) $pattern;

        if ($pattern === '') {
            return '';
        }

        if (substr($pattern, -1) === '%') {
            $pattern = substr($pattern, 0, -1);
        }

        return str_replace('\\', '', $pattern);
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite()
    {
        return !empty($GLOBALS['sitepulse_is_multisite']);
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($key)
    {
        global $wpdb;

        $GLOBALS['sitepulse_deleted_transients'][] = $key;

        if ($wpdb instanceof Sitepulse_Test_WPDB) {
            $wpdb->delete_transient_rows($key);
        }

        return true;
    }
}

if (!function_exists('delete_site_transient')) {
    function delete_site_transient($key)
    {
        global $wpdb;

        $GLOBALS['sitepulse_deleted_site_transients'][] = $key;

        if ($wpdb instanceof Sitepulse_Test_WPDB) {
            $wpdb->delete_site_transient_rows($key);
        }

        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value)
    {
        return $value;
    }
}

require_once dirname(__DIR__) . '/includes/functions.php';

function sitepulse_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$wpdb = new Sitepulse_Test_WPDB();
$GLOBALS['wpdb'] = $wpdb;

$wpdb->set_option_rows([
    ['option_name' => '_transient_sitepulse_speed', 'option_value' => 'ok'],
    ['option_name' => '_transient_timeout_sitepulse_speed', 'option_value' => time() + 60],
    ['option_name' => '_transient_sitepulse_error', 'option_value' => 'ok'],
    ['option_name' => '_transient_timeout_sitepulse_error', 'option_value' => time() + 60],
    ['option_name' => '_transient_other_metric', 'option_value' => 'ok'],
    ['option_name' => '_transient_timeout_other_metric', 'option_value' => time() + 60],
]);

sitepulse_delete_transients_by_prefix('sitepulse_');

sitepulse_assert(!$wpdb->has_option('_transient_sitepulse_speed'), 'Les valeurs ciblées doivent être supprimées.');
sitepulse_assert(!$wpdb->has_option('_transient_timeout_sitepulse_speed'), 'Les timeouts ciblés doivent être supprimés.');
sitepulse_assert(!$wpdb->has_option('_transient_sitepulse_error'), 'Toutes les valeurs correspondant au préfixe doivent être supprimées.');
sitepulse_assert(!$wpdb->has_option('_transient_timeout_sitepulse_error'), 'Tous les timeouts correspondant au préfixe doivent être supprimés.');
sitepulse_assert($wpdb->has_option('_transient_other_metric'), 'Les transients non ciblés doivent être préservés.');
sitepulse_assert($wpdb->has_option('_transient_timeout_other_metric'), 'Les timeouts non ciblés doivent être préservés.');

$deleted_transients = $GLOBALS['sitepulse_deleted_transients'];
sort($deleted_transients);
sitepulse_assert($deleted_transients === ['sitepulse_error', 'sitepulse_speed'], 'Seules les clés ciblées doivent être supprimées.');

$GLOBALS['sitepulse_deleted_transients'] = [];

$wpdb->set_sitemeta_rows([
    ['meta_key' => '_site_transient_sitepulse_queue', 'meta_value' => '1'],
    ['meta_key' => '_site_transient_timeout_sitepulse_queue', 'meta_value' => time() + 60],
    ['meta_key' => '_site_transient_sitepulse_digest', 'meta_value' => '1'],
    ['meta_key' => '_site_transient_timeout_sitepulse_digest', 'meta_value' => time() + 60],
    ['meta_key' => '_site_transient_other_queue', 'meta_value' => '1'],
    ['meta_key' => '_site_transient_timeout_other_queue', 'meta_value' => time() + 60],
]);

$GLOBALS['sitepulse_is_multisite'] = true;

sitepulse_delete_site_transients_by_prefix('sitepulse_');

sitepulse_assert(!$wpdb->has_meta('_site_transient_sitepulse_queue'), 'Les transients réseau ciblés doivent être supprimés.');
sitepulse_assert(!$wpdb->has_meta('_site_transient_timeout_sitepulse_queue'), 'Les timeouts réseau ciblés doivent être supprimés.');
sitepulse_assert(!$wpdb->has_meta('_site_transient_sitepulse_digest'), 'Toutes les valeurs réseau correspondant au préfixe doivent être supprimées.');
sitepulse_assert(!$wpdb->has_meta('_site_transient_timeout_sitepulse_digest'), 'Tous les timeouts réseau correspondant au préfixe doivent être supprimés.');
sitepulse_assert($wpdb->has_meta('_site_transient_other_queue'), 'Les transients réseau non ciblés doivent être préservés.');
sitepulse_assert($wpdb->has_meta('_site_transient_timeout_other_queue'), 'Les timeouts réseau non ciblés doivent être préservés.');

$deleted_site_transients = $GLOBALS['sitepulse_deleted_site_transients'];
sort($deleted_site_transients);
sitepulse_assert($deleted_site_transients === ['sitepulse_digest', 'sitepulse_queue'], 'Seules les clés réseau ciblées doivent être supprimées.');

echo "Tous les tests de nettoyage de transients sont passés." . PHP_EOL;
