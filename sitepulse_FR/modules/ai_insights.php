<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() { add_submenu_page('sitepulse-dashboard', 'AI Insights', 'AI Insights', 'manage_options', 'sitepulse-ai', 'ai_insights_page'); });
function ai_insights_page() {
    $api_key = get_option('sitepulse_gemini_api_key');
    $insight_result = get_transient('sitepulse_ai_insight');
    if (isset($_POST['get_ai_insight']) && check_admin_referer('sitepulse_get_ai_insight')) {
        if (empty($api_key)) {
            echo '<div class="notice notice-error"><p>Veuillez entrer votre clé API Google Gemini dans les réglages de SitePulse.</p></div>';
        } else {
            // Logic to call Gemini API...
        }
    }
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-superhero"></span> Analyses par IA</h1>
        <p>Obtenez des recommandations personnalisées pour votre site en analysant ses données de performance avec l'IA Gemini de Google.</p>
        <?php if (empty($api_key)): ?>
            <div class="notice notice-warning"><p>Veuillez <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-settings')); ?>">entrer votre clé API Google Gemini</a> pour utiliser cette fonctionnalité.</p></div>
        <?php else: ?>
            <form method="post" action=""><button type="submit" name="get_ai_insight" class="button button-primary">Générer une Analyse</button></form>
        <?php endif; ?>
        <?php if ($insight_result): ?>
            <div id="ai-insight-response" style="background: #fff; border: 1px solid #ccc; padding: 15px; margin-top: 20px;"><h2>Votre Recommandation par IA</h2><p><?php echo nl2br(esc_html($insight_result)); ?></p></div>
        <?php endif; ?>
    </div>
    <?php
}