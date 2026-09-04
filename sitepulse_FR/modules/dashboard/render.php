<?php
/**
 * SitePulse dashboard HTML render helpers.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

function sitepulse_render_dashboard_banner_kpi($kpi) {
    if (!is_array($kpi)) {
        return '';
    }

    $classes = ['sitepulse-status-banner__kpi'];
    $key     = isset($kpi['key']) ? sanitize_key((string) $kpi['key']) : '';
    $status  = isset($kpi['status']) ? sanitize_html_class((string) $kpi['status']) : 'ok';

    if ($status !== '') {
        $classes[] = 'sitepulse-status-banner__kpi--' . $status;
    }

    $icon_class = isset($kpi['icon']) ? (string) $kpi['icon'] : 'dashicons-chart-area';
    if (strpos($icon_class, 'dashicons') === false) {
        $icon_class = 'dashicons ' . $icon_class;
    }

    $label = isset($kpi['label']) ? (string) $kpi['label'] : '';
    $value = isset($kpi['value']) ? (string) $kpi['value'] : '';
    $meta  = isset($kpi['meta']) ? (string) $kpi['meta'] : '';
    $summary = isset($kpi['summary']) ? (string) $kpi['summary'] : '';

    $trend = isset($kpi['trend']) && is_array($kpi['trend']) ? $kpi['trend'] : null;
    $trend_text = ($trend && isset($trend['text'])) ? (string) $trend['text'] : '';
    $trend_direction = ($trend && isset($trend['direction'])) ? sanitize_html_class((string) $trend['direction']) : 'flat';
    $trend_sr = ($trend && isset($trend['sr'])) ? (string) $trend['sr'] : '';

    $items = isset($kpi['items']) && is_array($kpi['items']) ? $kpi['items'] : [];
    $empty_message = isset($kpi['empty_message']) ? (string) $kpi['empty_message'] : '';

    $sparkline = isset($kpi['sparkline']) && is_array($kpi['sparkline']) ? $kpi['sparkline'] : [];
    $sparkline_sr = isset($kpi['sparkline_sr']) ? (string) $kpi['sparkline_sr'] : '';

    ob_start();
    ?>
    <article class="<?php echo esc_attr(implode(' ', $classes)); ?>"<?php echo $key !== '' ? ' data-sitepulse-banner-kpi="' . esc_attr($key) . '"' : ''; ?>>
        <div class="sitepulse-status-banner__kpi-top">
            <span class="<?php echo esc_attr(trim('sitepulse-status-banner__kpi-icon ' . $icon_class)); ?>" aria-hidden="true"></span>
            <div class="sitepulse-status-banner__kpi-body">
                <span class="sitepulse-status-banner__kpi-label"><?php echo esc_html($label); ?></span>
                <span class="sitepulse-status-banner__kpi-value" data-sitepulse-banner-kpi-value><?php echo esc_html($value); ?></span>
                <?php if ($meta !== '') : ?>
                    <span class="sitepulse-status-banner__kpi-meta" data-sitepulse-banner-kpi-meta><?php echo esc_html($meta); ?></span>
                <?php endif; ?>
                <?php if ($trend_text !== '') : ?>
                    <span class="sitepulse-status-banner__kpi-trend" data-trend="<?php echo esc_attr($trend_direction); ?>" data-sitepulse-banner-kpi-trend>
                        <?php echo esc_html($trend_text); ?>
                    </span>
                    <?php if ($trend_sr !== '') : ?>
                        <span class="screen-reader-text"><?php echo esc_html($trend_sr); ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($summary !== '') : ?>
            <p class="sitepulse-status-banner__kpi-summary" data-sitepulse-banner-kpi-summary><?php echo esc_html($summary); ?></p>
        <?php endif; ?>
        <?php if (!empty($items)) : ?>
            <ul class="sitepulse-status-banner__kpi-list" data-sitepulse-banner-kpi-items>
                <?php foreach ($items as $item) :
                    if (!is_array($item)) {
                        continue;
                    }

                    $item_label = isset($item['label']) ? (string) $item['label'] : '';
                    $item_description = isset($item['description']) ? (string) $item['description'] : '';
                    $item_severity = isset($item['severity']) ? sanitize_html_class((string) $item['severity']) : '';
                ?>
                    <li class="sitepulse-status-banner__kpi-item<?php echo $item_severity !== '' ? ' sitepulse-status-banner__kpi-item--' . esc_attr($item_severity) : ''; ?>">
                        <span class="sitepulse-status-banner__kpi-item-label"><?php echo esc_html($item_label); ?></span>
                        <?php if ($item_description !== '') : ?>
                            <span class="sitepulse-status-banner__kpi-item-description"><?php echo esc_html($item_description); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php elseif ($empty_message !== '') : ?>
            <p class="sitepulse-status-banner__kpi-empty" data-sitepulse-banner-kpi-empty><?php echo esc_html($empty_message); ?></p>
        <?php endif; ?>
        <?php if (!empty($sparkline)) :
            $bars = array_filter($sparkline, static function ($point) {
                return is_array($point) && isset($point['relative']);
            });
        ?>
            <div class="sitepulse-status-banner__sparkline" data-sitepulse-banner-kpi-sparkline aria-hidden="true">
                <?php foreach ($bars as $point) :
                    $relative = max(0.0, min(1.0, (float) $point['relative']));
                    $height   = (int) round($relative * 100);
                    $label    = isset($point['label']) ? (string) $point['label'] : '';
                ?>
                    <span class="sitepulse-status-banner__sparkline-bar" style="--sitepulse-sparkline-height: <?php echo esc_attr($height); ?>%"<?php echo $label !== '' ? ' title="' . esc_attr($label) . '"' : ''; ?>></span>
                <?php endforeach; ?>
            </div>
            <?php if ($sparkline_sr !== '') : ?>
                <span class="screen-reader-text" data-sitepulse-banner-kpi-sparkline-sr><?php echo esc_html($sparkline_sr); ?></span>
            <?php endif; ?>
        <?php endif; ?>
    </article>
    <?php

    return (string) ob_get_clean();
}

function sitepulse_render_dashboard_banner_kpis($kpis) {
    $kpis = is_array($kpis) ? array_filter($kpis, 'is_array') : [];

    ob_start();
    ?>
    <div class="sitepulse-status-banner__kpi-grid" data-sitepulse-banner-kpis<?php echo empty($kpis) ? ' hidden' : ''; ?>>
        <?php foreach ($kpis as $kpi) : ?>
            <?php echo sitepulse_render_dashboard_banner_kpi($kpi); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function sitepulse_render_dashboard_metric_card($card_key, $card_view) {
    if (!is_array($card_view)) {
        return '';
    }

    $classes = ['sitepulse-kpi-card'];
    $status_class = isset($card_view['status']['class']) ? sanitize_html_class((string) $card_view['status']['class']) : '';

    if ($status_class !== '') {
        $classes[] = 'sitepulse-kpi-card--' . $status_class;
    }

    if (!empty($card_view['inactive'])) {
        $classes[] = 'sitepulse-kpi-card--inactive';
    }

    $status_meta = isset($card_view['status']) && is_array($card_view['status'])
        ? $card_view['status']
        : sitepulse_custom_dashboard_resolve_status_meta('status-warn');

    $status_label = isset($status_meta['label']) ? $status_meta['label'] : __('Status unknown', 'sitepulse');
    $status_icon  = isset($status_meta['icon']) ? $status_meta['icon'] : '⚠️';
    $status_sr    = isset($status_meta['sr']) ? $status_meta['sr'] : __('Status: unknown', 'sitepulse');

    $value_text = isset($card_view['value']['text']) ? (string) $card_view['value']['text'] : __('N/A', 'sitepulse');
    $value_unit = isset($card_view['value']['unit']) ? (string) $card_view['value']['unit'] : '';
    $summary    = isset($card_view['summary']) ? (string) $card_view['summary'] : '';

    $trend   = isset($card_view['trend']) && is_array($card_view['trend']) ? $card_view['trend'] : [];
    $trend_text = isset($trend['text']) ? (string) $trend['text'] : '';
    $trend_direction = isset($trend['direction']) ? sanitize_html_class((string) $trend['direction']) : 'flat';
    $trend_sr = isset($trend['sr']) ? (string) $trend['sr'] : '';

    $description = isset($card_view['description']) ? (string) $card_view['description'] : '';
    $inactive_message = isset($card_view['inactive_message'])
        ? (string) $card_view['inactive_message']
        : __('Enable the related module to view this metric.', 'sitepulse');

    ob_start();
    ?>
    <article class="<?php echo esc_attr(implode(' ', $classes)); ?>" data-sitepulse-metric-card="<?php echo esc_attr($card_key); ?>" data-status="<?php echo esc_attr($status_class); ?>"<?php echo !empty($card_view['inactive']) ? ' data-inactive=\"true\"' : ''; ?>>
        <header class="sitepulse-kpi-card__header">
            <h2 class="sitepulse-kpi-card__title" data-sitepulse-metric-label><?php echo esc_html(isset($card_view['label']) ? $card_view['label'] : ucfirst($card_key)); ?></h2>
            <span class="status-badge <?php echo esc_attr($status_class); ?>" data-sitepulse-metric-status-badge>
                <span class="status-icon" data-sitepulse-metric-status-icon><?php echo esc_html($status_icon); ?></span>
                <span class="status-text" data-sitepulse-metric-status-label><?php echo esc_html($status_label); ?></span>
            </span>
            <span class="screen-reader-text" data-sitepulse-metric-status-sr><?php echo esc_html($status_sr); ?></span>
        </header>
        <p class="sitepulse-kpi-card__value">
            <span class="sitepulse-kpi-card__value-number" data-sitepulse-metric-value><?php echo esc_html($value_text); ?></span>
            <span class="sitepulse-kpi-card__value-unit" data-sitepulse-metric-unit<?php echo $value_unit === '' ? ' hidden' : ''; ?>><?php echo esc_html($value_unit); ?></span>
        </p>
        <p class="sitepulse-kpi-card__summary" data-sitepulse-metric-summary<?php echo $summary === '' ? ' hidden' : ''; ?>><?php echo esc_html($summary); ?></p>
        <p class="sitepulse-kpi-card__trend" data-sitepulse-metric-trend data-trend="<?php echo esc_attr($trend_direction); ?>"<?php echo $trend_text === '' ? ' hidden' : ''; ?>>
            <span aria-hidden="true" data-sitepulse-metric-trend-text><?php echo esc_html($trend_text); ?></span>
            <span class="screen-reader-text" data-sitepulse-metric-trend-sr><?php echo esc_html($trend_sr); ?></span>
        </p>
        <?php
        $details = isset($card_view['details']) && is_array($card_view['details']) ? $card_view['details'] : [];
        ?>
        <dl class="sitepulse-kpi-card__details" data-sitepulse-metric-details<?php echo empty($details) ? ' hidden' : ''; ?>>
            <?php foreach ($details as $detail) :
                $detail_label = isset($detail['label']) ? (string) $detail['label'] : '';
                $detail_value = isset($detail['value']) ? (string) $detail['value'] : '';
                if ($detail_label === '' && $detail_value === '') {
                    continue;
                }
            ?>
                <div class="sitepulse-kpi-card__detail">
                    <dt><?php echo esc_html($detail_label); ?></dt>
                    <dd><?php echo esc_html($detail_value); ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
        <p class="sitepulse-kpi-card__description" data-sitepulse-metric-description<?php echo $description === '' ? ' hidden' : ''; ?>><?php echo esc_html($description); ?></p>
        <p class="sitepulse-kpi-card__inactive" data-sitepulse-metric-inactive<?php echo empty($card_view['inactive']) ? ' hidden' : ''; ?>><?php echo esc_html($inactive_message); ?></p>
    </article>
    <?php

    return (string) ob_get_clean();
}
