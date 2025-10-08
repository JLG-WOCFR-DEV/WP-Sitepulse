<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the absolute path to the SitePulse MU loader file.
 *
 * @return array{dir:string,file:string}
 */
function sitepulse_plugin_impact_get_mu_loader_paths() {
    $mu_dir = trailingslashit(WP_CONTENT_DIR) . 'mu-plugins';

    return [
        'dir'  => $mu_dir,
        'file' => trailingslashit($mu_dir) . 'sitepulse-impact-loader.php',
    ];
}

/**
 * Returns the expected MU loader contents for the current installation.
 *
 * @return string
 */
function sitepulse_plugin_impact_get_loader_contents() {
    $plugin_basename = sitepulse_get_stored_plugin_basename();
    $option_name     = SITEPULSE_OPTION_PLUGIN_BASENAME;

    $exported_option_name = var_export($option_name, true);
    $exported_basename    = var_export($plugin_basename, true);

    $contents = <<<'PHP'
<?php
/**
 * Plugin Name: SitePulse Impact Bootstrap
 * Description: Ensures SitePulse plugin impact tracking is hooked before standard plugins load.
 *
 * This file is auto-generated. Any manual edits may be overwritten.
 */

if (!defined('ABSPATH')) {
    exit;
}

$sitepulse_plugin_basename_option = %s;
$sitepulse_plugin_basename = get_option($sitepulse_plugin_basename_option);

if (!is_string($sitepulse_plugin_basename) || $sitepulse_plugin_basename === '') {
    $sitepulse_plugin_basename = %s;
}

$sitepulse_plugin_basename = ltrim($sitepulse_plugin_basename, '/\\');
$sitepulse_plugin_file = WP_PLUGIN_DIR . '/' . $sitepulse_plugin_basename;
$sitepulse_plugin_directory = dirname($sitepulse_plugin_file);

if ($sitepulse_plugin_directory === '.' || $sitepulse_plugin_directory === '') {
    $sitepulse_plugin_directory = WP_PLUGIN_DIR;
}

if (function_exists('trailingslashit')) {
    $sitepulse_plugin_directory = trailingslashit($sitepulse_plugin_directory);
} else {
    $sitepulse_plugin_directory = rtrim($sitepulse_plugin_directory, '/\\') . '/';
}

$sitepulse_tracker_file = $sitepulse_plugin_directory . 'includes/plugin-impact-tracker.php';

if (file_exists($sitepulse_tracker_file)) {
    require_once $sitepulse_tracker_file;

    if (function_exists('sitepulse_plugin_impact_tracker_bootstrap')) {
        sitepulse_plugin_impact_tracker_bootstrap();
    }
}
PHP;

    return sprintf($contents, $exported_option_name, $exported_basename);
}

/**
 * Returns the checksum of the bundled MU loader file.
 *
 * @return string|null
 */
function sitepulse_plugin_impact_get_loader_signature() {
    $contents = sitepulse_plugin_impact_get_loader_contents();

    return sitepulse_plugin_impact_calculate_loader_signature($contents);
}

/**
 * Calculates the SHA-256 signature for the MU loader.
 *
 * @param string $contents Loader contents.
 *
 * @return string|null
 */
function sitepulse_plugin_impact_calculate_loader_signature($contents) {
    if (!is_string($contents) || $contents === '') {
        return null;
    }

    $signature = hash('sha256', $contents);

    return $signature !== false ? $signature : null;
}

/**
 * Ensures the MU loader directory exists and is writable.
 *
 * @param WP_Filesystem_Base|null $filesystem Filesystem abstraction instance.
 * @param string                  $target_dir Directory path.
 *
 * @return bool
 */
function sitepulse_plugin_impact_ensure_loader_directory($filesystem, $target_dir) {
    if ($filesystem instanceof WP_Filesystem_Base) {
        if (!$filesystem->is_dir($target_dir)) {
            $filesystem->mkdir($target_dir, defined('FS_CHMOD_DIR') ? FS_CHMOD_DIR : false);
        }

        if (!$filesystem->is_dir($target_dir) || !$filesystem->is_writable($target_dir)) {
            sitepulse_log(sprintf('SitePulse impact loader directory not writable via WP_Filesystem (%s).', $target_dir), 'ERROR');

            return false;
        }

        return true;
    }

    if (!is_dir($target_dir) && function_exists('wp_mkdir_p')) {
        wp_mkdir_p($target_dir);
    }

    if (!is_dir($target_dir) || !is_writable($target_dir)) {
        sitepulse_log(sprintf('SitePulse impact loader directory not writable (%s).', $target_dir), 'ERROR');

        return false;
    }

    return true;
}

/**
 * Checks whether the existing loader matches the expected signature.
 *
 * @param WP_Filesystem_Base|null $filesystem Filesystem abstraction instance.
 * @param string                  $target_file Loader file path.
 * @param string                  $signature   Expected signature.
 *
 * @return bool
 */
function sitepulse_plugin_impact_loader_matches_signature($filesystem, $target_file, $signature) {
    if (!is_string($signature) || $signature === '') {
        return false;
    }

    if ($filesystem instanceof WP_Filesystem_Base) {
        if (!$filesystem->exists($target_file)) {
            return false;
        }

        $contents = $filesystem->get_contents($target_file);

        if (!is_string($contents) || $contents === '') {
            return false;
        }

        $existing_signature = hash('sha256', $contents);

        return $existing_signature !== false && sitepulse_hash_equals($signature, $existing_signature);
    }

    if (!file_exists($target_file)) {
        return false;
    }

    $existing_signature = hash_file('sha256', $target_file);

    return $existing_signature !== false && sitepulse_hash_equals($signature, $existing_signature);
}

/**
 * Writes the MU loader to disk.
 *
 * @param WP_Filesystem_Base|null $filesystem Filesystem abstraction instance.
 * @param string                  $target_file Loader file path.
 * @param string                  $contents    Loader contents.
 *
 * @return bool
 */
function sitepulse_plugin_impact_write_loader($filesystem, $target_file, $contents) {
    $written = false;

    if ($filesystem instanceof WP_Filesystem_Base) {
        $written = $filesystem->put_contents($target_file, $contents, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : false);
    }

    if (!$written) {
        $result = file_put_contents($target_file, $contents);

        if ($result !== false) {
            $written = true;

            if (function_exists('chmod')) {
                @chmod($target_file, 0644);
            }
        }
    }

    return $written;
}

/**
 * Installs or refreshes the MU loader responsible for early instrumentation.
 *
 * @return void
 */
function sitepulse_plugin_impact_install_mu_loader() {
    $paths = sitepulse_plugin_impact_get_mu_loader_paths();
    $target_dir = $paths['dir'];
    $target_file = $paths['file'];

    sitepulse_update_plugin_basename_option();

    $loader_contents = sitepulse_plugin_impact_get_loader_contents();
    $signature = sitepulse_plugin_impact_calculate_loader_signature($loader_contents);

    if ($signature === null) {
        return;
    }

    $filesystem = sitepulse_get_filesystem();
    $warning_message = sprintf(
        __('SitePulse n’a pas pu installer le chargeur MU du suivi d’impact (%s). Vérifiez les permissions du dossier mu-plugins.', 'sitepulse'),
        $target_dir
    );

    if (!sitepulse_plugin_impact_ensure_loader_directory($filesystem, $target_dir)) {
        delete_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE);
        sitepulse_register_cron_warning('plugin_impact', $warning_message);

        return;
    }

    $has_valid_loader = sitepulse_plugin_impact_loader_matches_signature($filesystem, $target_file, $signature);

    if (!$has_valid_loader) {
        $written = sitepulse_plugin_impact_write_loader($filesystem, $target_file, $loader_contents);

        if (!$written) {
            delete_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE);
            sitepulse_log(sprintf('SitePulse impact loader installation failed for %s.', $target_file), 'ERROR');
            sitepulse_register_cron_warning('plugin_impact', $warning_message);

            return;
        }

        $has_valid_loader = sitepulse_plugin_impact_loader_matches_signature($filesystem, $target_file, $signature);
    }

    if ($has_valid_loader) {
        update_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE, $signature, false);
        sitepulse_clear_cron_warning('plugin_impact');

        return;
    }

    delete_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE);
    sitepulse_log(sprintf('SitePulse impact loader installation failed for %s.', $target_file), 'ERROR');
    sitepulse_register_cron_warning('plugin_impact', $warning_message);
}

/**
 * Removes the SitePulse MU loader.
 *
 * @return void
 */
function sitepulse_plugin_impact_remove_mu_loader() {
    $paths = sitepulse_plugin_impact_get_mu_loader_paths();
    $target_file = $paths['file'];
    $filesystem = sitepulse_get_filesystem();
    $deleted = false;

    if ($filesystem instanceof WP_Filesystem_Base && $filesystem->exists($target_file)) {
        $deleted = $filesystem->delete($target_file);
    }

    if (!$deleted && file_exists($target_file)) {
        $deleted = @unlink($target_file);
    }

    if (!$deleted && file_exists($target_file)) {
        sitepulse_log(sprintf('SitePulse impact loader removal failed for %s.', $target_file), 'ERROR');
    }

    delete_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE);
}

/**
 * Refreshes the MU loader when the bundle signature changed.
 *
 * @return void
 */
function sitepulse_plugin_impact_maybe_refresh_mu_loader() {
    $signature = sitepulse_plugin_impact_get_loader_signature();
    $stored_signature = get_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE);

    if (!is_string($signature) || $signature === '') {
        return;
    }

    if (is_string($stored_signature) && sitepulse_hash_equals($signature, $stored_signature)) {
        return;
    }

    $paths = sitepulse_plugin_impact_get_mu_loader_paths();
    $target_file = $paths['file'];

    if (!file_exists($target_file)) {
        sitepulse_plugin_impact_install_mu_loader();

        return;
    }

    $existing_signature = hash_file('sha256', $target_file);

    if ($existing_signature !== false && sitepulse_hash_equals($signature, $existing_signature)) {
        update_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE, $signature, false);

        return;
    }

    sitepulse_plugin_impact_install_mu_loader();
}
