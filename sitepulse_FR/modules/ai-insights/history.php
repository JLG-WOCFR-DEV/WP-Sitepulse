<?php
/**
 * SitePulse AI Insights history storage.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the maximum number of AI history entries to keep.
 *
 * @return int
 */
function sitepulse_ai_get_history_max_entries() {
    $max_entries = (int) apply_filters('sitepulse_ai_history_max_entries', 20);

    if ($max_entries <= 0) {
        $max_entries = 20;
    }

    return $max_entries;
}

/**
 * Generates a deterministic identifier for a history entry.
 *
 * @param array<string,mixed> $entry History entry data.
 *
 * @return string
 */
function sitepulse_ai_generate_history_entry_id(array $entry) {
    $parts = [
        isset($entry['timestamp']) ? (string) absint($entry['timestamp']) : '',
        isset($entry['model']) && is_array($entry['model']) && isset($entry['model']['key'])
            ? sanitize_text_field((string) $entry['model']['key'])
            : (isset($entry['model_key']) ? sanitize_text_field((string) $entry['model_key']) : ''),
        isset($entry['rate_limit']) && is_array($entry['rate_limit']) && isset($entry['rate_limit']['key'])
            ? sanitize_text_field((string) $entry['rate_limit']['key'])
            : (isset($entry['rate_limit_key']) ? sanitize_text_field((string) $entry['rate_limit_key']) : ''),
        isset($entry['text']) ? sitepulse_ai_sanitize_insight_text($entry['text']) : '',
    ];

    $hash = md5(implode('|', $parts));

    return substr($hash, 0, 12);
}

/**
 * Retrieves stored notes keyed by history entry identifiers.
 *
 * @return array<string,string>
 */
function sitepulse_ai_get_history_notes() {
    if (!function_exists('get_option')) {
        return [];
    }

    $notes = get_option(SITEPULSE_OPTION_AI_HISTORY_NOTES, []);

    if (!is_array($notes)) {
        return [];
    }

    $sanitized = [];

    foreach ($notes as $entry_id => $note) {
        $key = sanitize_key((string) $entry_id);

        if ('' === $key) {
            continue;
        }

        $sanitized[$key] = sanitize_textarea_field((string) $note);
    }

    return $sanitized;
}

/**
 * Persists the given history notes array.
 *
 * @param array<string,string> $notes Notes keyed by entry identifier.
 *
 * @return void
 */
function sitepulse_ai_update_history_notes(array $notes) {
    if (!function_exists('update_option')) {
        return;
    }

    $normalized = [];

    foreach ($notes as $entry_id => $note) {
        $key = sanitize_key((string) $entry_id);

        if ('' === $key) {
            continue;
        }

        $value = sanitize_textarea_field((string) $note);

        if ('' === $value) {
            continue;
        }

        $normalized[$key] = $value;
    }

    update_option(SITEPULSE_OPTION_AI_HISTORY_NOTES, $normalized, false);
}

/**
 * Removes notes that no longer match existing history entries.
 *
 * @param array<int,array<string,mixed>> $history_entries Stored history entries.
 *
 * @return void
 */
function sitepulse_ai_prune_history_notes(array $history_entries) {
    if (!function_exists('update_option')) {
        return;
    }

    $notes = sitepulse_ai_get_history_notes();

    if (empty($notes)) {
        return;
    }

    $valid_ids = [];

    foreach ($history_entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $entry_id = '';

        if (isset($entry['id'])) {
            $entry_id = (string) $entry['id'];
        }

        if ('' === $entry_id) {
            $entry_id = sitepulse_ai_generate_history_entry_id($entry);
        }

        $entry_id = sanitize_key($entry_id);

        if ('' === $entry_id) {
            continue;
        }

        $valid_ids[$entry_id] = true;
    }

    $cleaned_notes = array_intersect_key($notes, $valid_ids);

    if ($cleaned_notes !== $notes) {
    update_option(SITEPULSE_OPTION_AI_HISTORY_NOTES, $cleaned_notes, false);
    }
}

/**
 * Prepares export-ready rows from history entries.
 *
 * @param array<int,array<string,mixed>> $entries History entries.
 *
 * @return array<int,array<string,string|int>>
 */
function sitepulse_ai_prepare_history_export_rows(array $entries) {
    $rows = [];

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $normalized_entry = sitepulse_ai_normalize_history_entry($entry);

        if (null === $normalized_entry) {
            continue;
        }

        if (isset($entry['note'])) {
            $normalized_entry['note'] = sanitize_textarea_field((string) $entry['note']);
        }

        $timestamp = isset($normalized_entry['timestamp']) ? (int) $normalized_entry['timestamp'] : 0;
        $display   = '';
        $iso8601   = '';

        if ($timestamp > 0) {
            if (function_exists('date_i18n')) {
                $display = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
            } else {
                $display = gmdate('Y-m-d H:i:s', $timestamp);
            }

            $iso8601 = gmdate('c', $timestamp);
        }

        $rows[] = [
            'id'                => isset($normalized_entry['id']) ? (string) $normalized_entry['id'] : sitepulse_ai_generate_history_entry_id($normalized_entry),
            'timestamp'         => $timestamp,
            'timestamp_display' => $display,
            'timestamp_iso'     => $iso8601,
            'model'             => isset($normalized_entry['model']['label']) ? (string) $normalized_entry['model']['label'] : '',
            'model_key'         => isset($normalized_entry['model']['key']) ? (string) $normalized_entry['model']['key'] : '',
            'rate_limit'        => isset($normalized_entry['rate_limit']['label']) ? (string) $normalized_entry['rate_limit']['label'] : '',
            'rate_limit_key'    => isset($normalized_entry['rate_limit']['key']) ? (string) $normalized_entry['rate_limit']['key'] : '',
            'text'              => isset($normalized_entry['text']) ? (string) $normalized_entry['text'] : '',
            'note'              => isset($normalized_entry['note']) ? (string) $normalized_entry['note'] : '',
        ];
    }

    return $rows;
}

/**
 * Normalizes a raw AI history entry.
 *
 * @param array<string,mixed> $entry Raw history entry data.
 *
 * @return array{
 *     id:string,
 *     text:string,
 *     html:string,
 *     timestamp:int,
 *     model:array{key:string,label:string},
 *     rate_limit:array{key:string,label:string},
 *     note:string
 * }|null
 */
function sitepulse_ai_normalize_history_entry($entry) {
    if (!is_array($entry)) {
        return null;
    }

    $variants = sitepulse_ai_prepare_insight_variants(
        isset($entry['text']) ? (string) $entry['text'] : '',
        isset($entry['html']) ? (string) $entry['html'] : ''
    );

    if ('' === $variants['text']) {
        return null;
    }

    $timestamp = isset($entry['timestamp']) ? absint($entry['timestamp']) : 0;

    $model_key   = '';
    $model_label = '';

    if (isset($entry['model']) && is_array($entry['model'])) {
        if (isset($entry['model']['key'])) {
            $model_key = sanitize_text_field((string) $entry['model']['key']);
        }

        if (isset($entry['model']['label'])) {
            $model_label = sanitize_text_field((string) $entry['model']['label']);
        }
    } else {
        if (isset($entry['model_key'])) {
            $model_key = sanitize_text_field((string) $entry['model_key']);
        }

        if (isset($entry['model_label'])) {
            $model_label = sanitize_text_field((string) $entry['model_label']);
        }
    }

    if ('' === $model_label) {
        $model_label = $model_key;
    }

    $rate_limit_key   = '';
    $rate_limit_label = '';

    if (isset($entry['rate_limit']) && is_array($entry['rate_limit'])) {
        if (isset($entry['rate_limit']['key'])) {
            $rate_limit_key = sanitize_text_field((string) $entry['rate_limit']['key']);
        }

        if (isset($entry['rate_limit']['label'])) {
            $rate_limit_label = sanitize_text_field((string) $entry['rate_limit']['label']);
        }
    } else {
        if (isset($entry['rate_limit_key'])) {
            $rate_limit_key = sanitize_text_field((string) $entry['rate_limit_key']);
        }

        if (isset($entry['rate_limit_label'])) {
            $rate_limit_label = sanitize_text_field((string) $entry['rate_limit_label']);
        }
    }

    if ('' === $rate_limit_label) {
        $rate_limit_label = $rate_limit_key;
    }

    $normalized = [
        'text'      => $variants['text'],
        'html'      => $variants['html'],
        'timestamp' => $timestamp,
        'model'     => [
            'key'   => $model_key,
            'label' => $model_label,
        ],
        'rate_limit' => [
            'key'   => $rate_limit_key,
            'label' => $rate_limit_label,
        ],
        'note' => '',
    ];

    $normalized['id'] = sitepulse_ai_generate_history_entry_id($normalized);

    return $normalized;
}

/**
 * Appends an AI insight result to the persistent history option.
 *
 * @param array<string,mixed> $entry History entry data.
 *
 * @return void
 */
function sitepulse_ai_record_history_entry(array $entry) {
    if (!function_exists('get_option') || !function_exists('update_option')) {
        return;
    }

    $normalized_entry = sitepulse_ai_normalize_history_entry($entry);

    if (null === $normalized_entry) {
        return;
    }

    $history = get_option(SITEPULSE_OPTION_AI_HISTORY, []);

    if (!is_array($history)) {
        $history = [];
    }

    $history[]    = $normalized_entry;
    $max_entries  = sitepulse_ai_get_history_max_entries();
    $history_size = count($history);

    if ($max_entries > 0 && $history_size > $max_entries) {
        $history = array_slice($history, -$max_entries, $max_entries, true);
    }

    $history = array_values($history);

    update_option(SITEPULSE_OPTION_AI_HISTORY, $history, false);

    sitepulse_ai_prune_history_notes($history);
}

/**
 * Retrieves the stored AI insight history entries ordered from newest to oldest.
 *
 * @return array<int,array{
 *     id:string,
 *     text:string,
 *     html:string,
 *     timestamp:int,
 *     model:array{key:string,label:string},
 *     rate_limit:array{key:string,label:string},
 *     note:string,
 *     timestamp_display:string,
 *     timestamp_iso:string
 * }>
 */
function sitepulse_ai_get_history_entries() {
    $history = function_exists('get_option') ? get_option(SITEPULSE_OPTION_AI_HISTORY, []) : [];

    if (!is_array($history)) {
        $history = [];
    }

    $normalized = [];
    $notes      = sitepulse_ai_get_history_notes();

    foreach ($history as $entry) {
        $normalized_entry = sitepulse_ai_normalize_history_entry($entry);

        if (null === $normalized_entry) {
            continue;
        }

        $entry_id = isset($normalized_entry['id']) ? sanitize_key((string) $normalized_entry['id']) : '';

        if ('' !== $entry_id && isset($notes[$entry_id])) {
            $normalized_entry['note'] = sanitize_textarea_field((string) $notes[$entry_id]);
        }

        $timestamp = isset($normalized_entry['timestamp']) ? (int) $normalized_entry['timestamp'] : 0;
        $display   = '';
        $iso8601   = '';

        if ($timestamp > 0) {
            if (function_exists('date_i18n')) {
                $display = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
            } else {
                $display = gmdate('Y-m-d H:i:s', $timestamp);
            }

            $iso8601 = gmdate('c', $timestamp);
        }

        $normalized_entry['timestamp_display'] = $display;
        $normalized_entry['timestamp_iso']     = $iso8601;

        $normalized[] = $normalized_entry;
    }

    if (!empty($normalized)) {
        usort($normalized, function ($a, $b) {
            $a_time = isset($a['timestamp']) ? (int) $a['timestamp'] : 0;
            $b_time = isset($b['timestamp']) ? (int) $b['timestamp'] : 0;

            if ($a_time === $b_time) {
                return 0;
            }

            return ($a_time < $b_time) ? 1 : -1;
        });
    }

    return array_values($normalized);
}

/**
 * Extracts unique filter options from the AI history entries.
 *
 * @param array<int,array<string,mixed>> $entries History entries.
 * @param string                         $key     Nested key to extract (e.g. 'model').
 *
 * @return array<int,array{key:string,label:string}>
 */
function sitepulse_ai_get_history_filter_options(array $entries, $key) {
    $options = [];

    foreach ($entries as $entry) {
        if (!is_array($entry) || !isset($entry[$key]) || !is_array($entry[$key])) {
            continue;
        }

        $value = isset($entry[$key]['key']) ? (string) $entry[$key]['key'] : '';

        if ('' === $value || isset($options[$value])) {
            continue;
        }

        $label = isset($entry[$key]['label']) ? (string) $entry[$key]['label'] : $value;

        $options[$value] = [
            'key'   => $value,
            'label' => $label,
        ];
    }

    return array_values($options);
}
