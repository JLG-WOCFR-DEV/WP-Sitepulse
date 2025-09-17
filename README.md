# SitePulse - JLG

SitePulse - JLG est un plugin WordPress développé par Jérôme Le Gousse. Il surveille le « pouls » d'un site en observant la vitesse, la base de données, la maintenance, le serveur et les erreurs afin d'offrir une visibilité continue sur la santé de WordPress.【F:sitepulse_FR/sitepulse.php†L1-L38】

## Fonctionnalités
- **Analyseur de vitesse** : mesure le temps de génération de page, les requêtes SQL et la configuration serveur pour identifier les goulets d'étranglement back-end.【F:sitepulse_FR/modules/speed_analyzer.php†L1-L130】
- **Moniteur de ressources** : suit la charge CPU, l'utilisation mémoire et l'espace disque avec des avertissements si les métriques sont indisponibles ou critiques.【F:sitepulse_FR/modules/resource_monitor.php†L1-L109】
- **Scanner d'impact des extensions** : calcule la charge de chaque plugin actif, conserve une moyenne mobile et affiche les extensions les plus coûteuses pour guider les optimisations.【F:sitepulse_FR/modules/plugin_impact_scanner.php†L1-L143】
- **Analyses par IA** : interroge l'API Gemini pour produire des recommandations en français sur la vitesse, le SEO et la conversion, avec mise en cache des résultats.【F:sitepulse_FR/modules/ai_insights.php†L1-L119】
- **Suivi de disponibilité** : planifie un contrôle horaire du front-office, enregistre les 30 derniers statuts et calcule le pourcentage de disponibilité avec visualisation rapide.【F:sitepulse_FR/modules/uptime_tracker.php†L1-L76】
- **Alertes d'erreurs** : surveille la charge CPU et le fichier `debug.log`, applique un délai anti-spam et envoie des e-mails d'alerte en cas de dépassement ou d'erreur fatale.【F:sitepulse_FR/modules/error_alerts.php†L1-L167】
- **Optimiseur de base de données** : supprime en un clic révisions et transients expirés, avec compteurs et messages pédagogiques pour sécuriser le nettoyage.【F:sitepulse_FR/modules/database_optimizer.php†L1-L66】
- **Analyseur de journaux** : lit les dernières entrées de `debug.log`, catégorise les messages (fatals, erreurs, avertissements, notices) et guide l'activation du logging WordPress.【F:sitepulse_FR/modules/log_analyzer.php†L1-L141】
- **Conseiller de maintenance** : recense les mises à jour cœur et extensions afin de rappeler les bonnes pratiques avant intervention.【F:sitepulse_FR/modules/maintenance_advisor.php†L1-L22】
- **Tableaux de bord personnalisés** : fournit une vue synthétique des indicateurs clés (TTFB, uptime, base de données, journal) et des raccourcis vers chaque module spécialisé.【F:sitepulse_FR/modules/custom_dashboards.php†L1-L121】

## Installation et configuration
1. **Activation** : installez le plugin via l'administration WordPress puis activez-le depuis la page des extensions pour créer le menu « Sitepulse - JLG » dans le tableau de bord.【F:sitepulse_FR/includes/admin-settings.php†L40-L81】
2. **Sélection des modules** : dans *Réglages > SitePulse*, cochez les modules à activer selon vos besoins de surveillance ; seuls les modules sélectionnés ajoutent leurs sous-menus et tâches programmées.【F:sitepulse_FR/includes/admin-settings.php†L233-L272】
3. **Clé Gemini** : saisissez la clé API Google Gemini pour débloquer les analyses IA et profitez des recommandations personnalisées.【F:sitepulse_FR/includes/admin-settings.php†L252-L262】【F:sitepulse_FR/modules/ai_insights.php†L10-L69】
4. **Alertes** : définissez le seuil d'alerte CPU et la fenêtre anti-spam pour contrôler la fréquence des e-mails envoyés par le module d'alertes.【F:sitepulse_FR/includes/admin-settings.php†L273-L287】【F:sitepulse_FR/modules/error_alerts.php†L9-L63】
5. **Mode debug et nettoyage** : activez le mode debug pour exposer le sous-menu de diagnostic, vider le journal de debug ou réinitialiser les données directement depuis la même page de réglages.【F:sitepulse_FR/includes/admin-settings.php†L265-L366】

## Intégrations et automatisations
- **Planification de la disponibilité** : le module de suivi programme un cron horaire (`wp_schedule_event`) pour exécuter `sitepulse_run_uptime_check`, conservant un historique glissant de 30 points et consignant les incidents.【F:sitepulse_FR/modules/uptime_tracker.php†L5-L74】
- **Boucle d'alertes automatisées** : un cron personnalisé toutes les cinq minutes orchestre la lecture du fichier `debug.log` et la surveillance de la charge CPU, avec verrouillage par transient pour éviter le spam d'e-mails.【F:sitepulse_FR/modules/error_alerts.php†L1-L121】
- **Query Monitor** : si l'extension est présente, SitePulse expose un collecteur dédié affichant les dernières mesures de temps de chargement et de disponibilité directement dans Query Monitor.【F:sitepulse_FR/includes/integrations.php†L1-L19】

## Maintenance
- **Mode debug** : en plus du logging détaillé, un tableau de bord dédié récapitule l'environnement, les crons SitePulse et le journal actif pour faciliter le diagnostic.【F:sitepulse_FR/includes/admin-settings.php†L367-L420】【F:sitepulse_FR/sitepulse.php†L18-L394】
- **Nettoyage et désinstallation** : les réglages permettent de purger journaux et données, tandis que la routine `uninstall.php` supprime options, transients, tâches planifiées et fichiers de log en toute sécurité lors de la suppression du plugin.【F:sitepulse_FR/includes/admin-settings.php†L333-L366】【F:sitepulse_FR/uninstall.php†L1-L126】
- **Pour aller plus loin** : consultez chaque module dans `sitepulse_FR/modules/` pour comprendre les métriques collectées, les interfaces générées et adapter vos interventions si besoin.【F:sitepulse_FR/modules/custom_dashboards.php†L1-L121】
