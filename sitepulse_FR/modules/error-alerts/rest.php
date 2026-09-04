<?php
/**
 * SitePulse Error Alerts REST routes.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the REST API endpoint for sending test alerts.
 *
 * @return void
 */
function sitepulse_error_alerts_register_rest_routes() {
    register_rest_route(
        'sitepulse/v1',
        '/alerts/test',
        [
            'methods'             => 'POST',
            'callback'            => 'sitepulse_error_alerts_handle_rest_test',
            'permission_callback' => 'sitepulse_error_alerts_rest_permissions',
        ]
    );
}

/**
 * Permission callback for the REST endpoint.
 *
 * @return bool
 */
function sitepulse_error_alerts_rest_permissions() {
    return current_user_can(sitepulse_get_capability());
}

/**
 * Handles REST API test alert requests.
 *
 * @param \WP_REST_Request $request The REST request instance.
 * @return \WP_REST_Response|\WP_Error
 */
function sitepulse_error_alerts_handle_rest_test($request) {
    $nonce = $request->get_param('_wpnonce');

    if ($nonce && !wp_verify_nonce($nonce, SITEPULSE_NONCE_ACTION_ALERT_TEST)) {
        return new WP_Error('sitepulse_invalid_nonce', __('Échec de la vérification de sécurité pour l’envoi de test.', 'sitepulse'), ['status' => 403]);
    }

    $channel = $request->get_param('channel');
    $channel = is_string($channel) ? sanitize_key($channel) : 'email';

    $result = sitepulse_error_alerts_send_test_message($channel);

    if (is_wp_error($result)) {
        switch ($result->get_error_code()) {
            case 'sitepulse_no_alert_recipients':
                $status = 400;
                break;
            case 'sitepulse_no_webhooks':
            case 'sitepulse_no_delivery_channels':
                $status = 400;
                break;
            default:
                $status = 500;
        }

        return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => $status]);
    }

    $message = $channel === 'webhook'
        ? esc_html__('Webhook de test déclenché avec succès.', 'sitepulse')
        : esc_html__('E-mail de test envoyé.', 'sitepulse');

    return rest_ensure_response([
        'success' => true,
        'message' => $message,
    ]);
}
