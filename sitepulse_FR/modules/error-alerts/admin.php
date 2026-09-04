<?php
/**
 * SitePulse Error Alerts admin test handlers.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the admin-post request triggered from the settings screen.
 *
 * @return void
 */
function sitepulse_error_alerts_handle_test_admin_post() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse'));
    }

    $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';

    if (!wp_verify_nonce($nonce, SITEPULSE_NONCE_ACTION_ALERT_TEST)) {
        wp_die(esc_html__('Échec de la vérification de sécurité pour l’envoi de test.', 'sitepulse'));
    }

    $channel = isset($_REQUEST['channel']) ? sanitize_key(wp_unslash($_REQUEST['channel'])) : 'email';

    $result = sitepulse_error_alerts_send_test_message($channel);
    $status = 'success';

    if (is_wp_error($result)) {
        switch ($result->get_error_code()) {
            case 'sitepulse_no_alert_recipients':
                $status = 'no_recipients';
                break;
            case 'sitepulse_no_webhooks':
                $status = 'no_webhooks';
                break;
            case 'sitepulse_no_delivery_channels':
                $status = 'no_channels';
                break;
            default:
                $status = 'error';
        }
    }

    $redirect_url = add_query_arg(
        [
            'sitepulse_alert_test'     => $status,
            'sitepulse_alert_channel'  => $channel,
        ],
        admin_url('admin.php?page=sitepulse-settings#sitepulse-section-alerts')
    );

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Handles AJAX test requests.
 *
 * @return void
 */
function sitepulse_error_alerts_handle_ajax_test() {
    check_ajax_referer(SITEPULSE_NONCE_ACTION_ALERT_TEST, 'nonce');

    if (!current_user_can(sitepulse_get_capability())) {
        wp_send_json_error([
            'message' => esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse'),
        ], 403);
    }

    $channel = isset($_POST['channel']) ? sanitize_key(wp_unslash($_POST['channel'])) : 'email';

    $result = sitepulse_error_alerts_send_test_message($channel);

    if (is_wp_error($result)) {
        switch ($result->get_error_code()) {
            case 'sitepulse_no_alert_recipients':
                $status_code = 400;
                break;
            case 'sitepulse_no_webhooks':
            case 'sitepulse_no_delivery_channels':
                $status_code = 400;
                break;
            default:
                $status_code = 500;
        }

        wp_send_json_error([
            'message' => esc_html($result->get_error_message()),
        ], $status_code);
    }

    $success_message = $channel === 'webhook'
        ? esc_html__('Webhook de test déclenché avec succès.', 'sitepulse')
        : esc_html__('E-mail de test envoyé.', 'sitepulse');

    wp_send_json_success([
        'message' => $success_message,
    ]);
}
