<?php
/**
 * SitePulse AI Insights HTML sanitizers.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the HTML tags allowed in AI insight content.
 *
 * @return array<string,mixed>
 */
function sitepulse_ai_get_allowed_insight_html_tags() {
    $allowed_tags = [
        'p'          => [],
        'br'         => [],
        'strong'     => [],
        'em'         => [],
        'ul'         => [],
        'ol'         => [],
        'li'         => [],
        'blockquote' => [],
        'code'       => [],
        'pre'        => [],
        'a'          => [
            'href'   => true,
            'rel'    => true,
            'target' => true,
            'title'  => true,
        ],
    ];

    /**
     * Filters the HTML tags allowed when sanitizing AI insight content.
     *
     * @param array<string,mixed> $allowed_tags Allowed HTML tags.
     */
    return (array) apply_filters('sitepulse_ai_insight_allowed_tags', $allowed_tags);
}

/**
 * Sanitizes AI insight HTML content.
 *
 * @param string $html Raw HTML content.
 *
 * @return string Sanitized HTML.
 */
function sitepulse_ai_sanitize_insight_html($html) {
    $html = (string) $html;

    if ('' === $html) {
        return '';
    }

    $sanitized = wp_kses($html, sitepulse_ai_get_allowed_insight_html_tags());

    return trim($sanitized);
}

/**
 * Sanitizes AI insight plain text content.
 *
 * @param string $text Raw text content.
 *
 * @return string Sanitized plain text.
 */
function sitepulse_ai_sanitize_insight_text($text) {
    $text = (string) $text;

    if ('' === $text) {
        return '';
    }

    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = wp_strip_all_tags($text, true);
    $text = preg_replace('/[ \t]+\n/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
}

/**
 * Builds sanitized HTML and text variants for AI insights.
 *
 * @param string $text Raw text content.
 * @param string $html Optional raw HTML content.
 *
 * @return array{text:string,html:string}
 */
function sitepulse_ai_prepare_insight_variants($text, $html = '') {
    $raw_text = (string) $text;
    $raw_html = (string) $html;

    if ('' === $raw_html && '' !== $raw_text) {
        $raw_html = wpautop($raw_text);
    }

    $sanitized_html = sitepulse_ai_sanitize_insight_html($raw_html);

    $text_source = '' !== $raw_text ? $raw_text : $sanitized_html;
    $sanitized_text = sitepulse_ai_sanitize_insight_text($text_source);

    if ('' === $sanitized_text && '' !== $sanitized_html) {
        $sanitized_text = sitepulse_ai_sanitize_insight_text($sanitized_html);
    }

    if ('' === $sanitized_html && '' !== $sanitized_text) {
        $sanitized_html = sitepulse_ai_sanitize_insight_html(wpautop($sanitized_text));
    }

    return [
        'text' => $sanitized_text,
        'html' => $sanitized_html,
    ];
}
