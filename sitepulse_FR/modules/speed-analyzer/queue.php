<?php
/**
 * SitePulse Speed Analyzer automation queue.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Retrieves the current automation queue.
 *
 * @return string[]
 */
function sitepulse_speed_analyzer_get_queue() {
    $queue = get_option(SITEPULSE_OPTION_SPEED_AUTOMATION_QUEUE, []);

    if (!is_array($queue)) {
        return [];
    }

    $normalized = [];

    foreach ($queue as $entry) {
        $parsed = sitepulse_speed_analyzer_parse_queue_token($entry);
        $normalized[] = sitepulse_speed_analyzer_build_queue_token($parsed['preset'], $parsed['source']);
    }

    return array_values(array_unique($normalized));
}

/**
 * Updates the automation queue.
 *
 * @param string[] $queue Queue entries.
 *
 * @return void
 */
function sitepulse_speed_analyzer_update_queue($queue) {
    if (!is_array($queue)) {
        $queue = [];
    }

    update_option(SITEPULSE_OPTION_SPEED_AUTOMATION_QUEUE, array_values($queue), false);
}

/**
 * Adds presets to the automation queue.
 *
 * @param array<int|string,mixed> $presets Preset definitions or slugs.
 *
 * @return void
 */
function sitepulse_speed_analyzer_enqueue_presets(array $presets) {
    $queue = sitepulse_speed_analyzer_get_queue();

    foreach ($presets as $key => $preset) {
        $slug = null;
        $config = null;

        if (is_string($key) && $key !== '' && is_array($preset)) {
            $slug = sanitize_key($key);
            $config = $preset;
        } elseif (is_string($preset)) {
            $slug = sanitize_key($preset);
        }

        if ($slug === null || $slug === '') {
            continue;
        }

        if (!is_array($config) && isset($presets[$slug]) && is_array($presets[$slug])) {
            $config = $presets[$slug];
        }

        $targets = sitepulse_speed_analyzer_resolve_targets_for_preset($slug, is_array($config) ? $config : []);

        if ($targets === []) {
            $token = sitepulse_speed_analyzer_build_queue_token($slug, 'site');

            if (!in_array($token, $queue, true)) {
                $queue[] = $token;
            }

            continue;
        }

        foreach ($targets as $target) {
            if (!isset($target['key'])) {
                continue;
            }

            $token = sitepulse_speed_analyzer_build_queue_token($slug, $target['key']);

            if (!in_array($token, $queue, true)) {
                $queue[] = $token;
            }
        }
    }

    sitepulse_speed_analyzer_update_queue($queue);
}

/**
 * Retrieves and removes the next preset from the queue.
 *
 * @return string|null
 */
function sitepulse_speed_analyzer_shift_queue() {
    $queue = sitepulse_speed_analyzer_get_queue();

    if ($queue === []) {
        return null;
    }

    $next = array_shift($queue);
    sitepulse_speed_analyzer_update_queue($queue);

    return $next;
}

/**
 * Determines whether the queue contains entries.
 *
 * @return bool
 */
function sitepulse_speed_analyzer_queue_not_empty() {
    return sitepulse_speed_analyzer_get_queue() !== [];
}
