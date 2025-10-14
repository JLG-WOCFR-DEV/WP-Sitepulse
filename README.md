# SitePulse - JLG

SitePulse - JLG est un plugin WordPress modulaire qui surveille la vitesse, la base de données, la maintenance, le serveur et les erreurs afin de donner en permanence le « pouls » d'un site.【F:sitepulse_FR/sitepulse.php†L3-L106】

## Fonctionnalités principales

### Modules de surveillance et d’optimisation
- **Analyseur de vitesse** : lance des audits manuels ou planifiés, met en file d’attente les scans quand la limite est atteinte et applique vos seuils d’alerte par profil (desktop/mobile) pour identifier les goulets d’étranglement back-end.【F:sitepulse_FR/modules/speed_analyzer.php†L5-L133】【F:sitepulse_FR/includes/functions.php†L200-L260】
- **Moniteur de ressources** : capture un instantané CPU/RAM/espace disque, applique vos seuils, journalise les indisponibilités et restitue l’historique dans une interface Chart.js avec export JSON.【F:sitepulse_FR/modules/resource_monitor.php†L8-L199】【F:sitepulse_FR/modules/resource_monitor.php†L305-L378】
- **Suivi de disponibilité** : planifie des contrôles via WP-Cron et REST, orchestre des workers distants/WP-CLI, gère les fenêtres de maintenance récurrentes et conserve les 30 derniers statuts avec annotations et alertes internes.【F:sitepulse_FR/modules/uptime_tracker.php†L28-L320】【F:sitepulse_FR/modules/uptime_tracker.php†L2203-L2290】
- **Scanner d’impact des extensions** : calcule la charge CPU/poids disque de chaque plugin, s’adapte aux rôles, nettoie les caches de taille et propose un export CSV pour isoler les extensions les plus coûteuses.【F:sitepulse_FR/modules/plugin_impact_scanner.php†L6-L189】
- **Analyses par IA** : interroge l’API Gemini avec gestion des quotas/retry, met en cache les réponses (catalogue de modèles inclus), ajoute des variantes HTML et fournit des outils accessibles pour copier, noter et exporter les recommandations.【F:sitepulse_FR/modules/ai_insights.php†L1606-L1759】【F:sitepulse_FR/modules/js/sitepulse-ai-insights.js†L430-L516】【F:sitepulse_FR/includes/functions.php†L250-L325】
- **Optimiseur de base de données** : supprime les révisions et transients expirés par lots avec feedback détaillé, tout en respectant les caches persistants et les environnements multisites.【F:sitepulse_FR/modules/database_optimizer.php†L3-L199】
- **Analyseur de journaux** : catégorise les entrées du `debug.log`, explique chaque gravité, détecte les lectures tronquées pour les gros fichiers et guide l’activation du logging quand il est inactif.【F:sitepulse_FR/modules/log_analyzer.php†L4-L170】【F:sitepulse_FR/includes/admin-settings.php†L3200-L3238】
- **Conseiller de maintenance** : synthétise les mises à jour cœur/extension/thème, détecte les correctifs de sécurité et expose les URL de changelog en fenêtre modale.【F:sitepulse_FR/modules/maintenance_advisor.php†L3-L160】
- **Tableaux de bord personnalisés** : charge des widgets filtrables, propose des résumés accessibles, mémorise l’ordre/visibilité par utilisateur et expose une barre de préférences pour réorganiser les cartes.【F:sitepulse_FR/modules/custom_dashboards.php†L20-L146】【F:sitepulse_FR/modules/custom_dashboards.php†L165-L276】【F:sitepulse_FR/modules/custom_dashboards.php†L388-L466】
- **Alertes d’erreurs** : surveille la charge CPU et les erreurs fatales, applique des seuils configurables, accepte des intervalles d’alerte fins ou « intelligents », évite le spam via cooldown, et diffuse les alertes par e-mail ou webhooks filtrables avec formatage natif pour Slack, Microsoft Teams et Discord.【F:sitepulse_FR/modules/error_alerts.php†L6-L140】【F:sitepulse_FR/modules/error_alerts.php†L560-L720】【F:sitepulse_FR/modules/error_alerts.php†L400-L520】

### Administration, automatisation et intégrations
- **Activation à la carte** : chaque module, seuil et canal d’alerte est paramétrable depuis la page « Réglages » avec des callbacks de validation dédiés (clé Gemini, maintenance, latence, révisions, etc.).【F:sitepulse_FR/includes/admin-settings.php†L70-L254】
- **Capacité dédiée** : la capacité filtrable `sitepulse_get_capability()` protège menus et sous-menus et est ajoutée automatiquement aux administrateurs lors de l’activation.【F:sitepulse_FR/includes/admin-settings.php†L12-L101】【F:sitepulse_FR/sitepulse.php†L1888-L1899】
- **Planification avancée** : le plugin enregistre ses propres fréquences Cron pour l’uptime, les alertes d’erreurs et les instantanés ressources, et nettoie les tâches quand un module est désactivé.【F:sitepulse_FR/modules/uptime_tracker.php†L28-L128】【F:sitepulse_FR/modules/error_alerts.php†L6-L40】【F:sitepulse_FR/sitepulse.php†L1185-L1240】
- **Site Health & Query Monitor** : SitePulse publie deux tests « Santé du site » (état général et clé Gemini) et expose ses métriques dans Query Monitor pour faciliter le diagnostic transversal.【F:sitepulse_FR/sitepulse.php†L660-L860】【F:sitepulse_FR/includes/integrations.php†L4-L29】
- **Outils développeurs** : support WP-CLI pour planifier un contrôle d’uptime, filtres dédiés (requêtes HTTP, modèles IA) et mode debug avec tableau de bord spécialisé.【F:sitepulse_FR/modules/uptime_tracker.php†L117-L130】【F:sitepulse_FR/sitepulse.php†L308-L420】

### Sécurité, maintenance et cycle de vie
- **Protection du debug log** : relocation automatique hors webroot quand les protections `.htaccess/web.config` sont ignorées, génération des fichiers de blocage, alertes si l’écriture échoue et affichage contextualisé (tronqué au besoin) dans les écrans d’administration.【F:sitepulse_FR/sitepulse.php†L330-L379】【F:sitepulse_FR/includes/admin-settings.php†L3200-L3238】
- **Nettoyage complet** : la routine `uninstall.php` supprime options, transients, tâches cron, signatures MU et capacités personnalisées pour laisser la base propre, les helpers de purge opèrent par lots avec invalidation explicite des caches persistants et les statistiques de nettoyage sont historisées dans les réglages (avec widget du tableau de bord) pour identifier les préfixes les plus gourmands.【F:sitepulse_FR/uninstall.php†L14-L200】【F:sitepulse_FR/includes/functions.php†L12-L240】【F:sitepulse_FR/includes/admin-settings.php†L3208-L3268】

## Export et partage des recommandations IA
- Depuis le tableau de bord « Analyses par IA », la barre d’outils de l’historique propose deux actions : **Exporter en CSV** (télécharge un fichier UTF‑8 structuré avec la date, le modèle, la limitation, le texte et la note) et **Copier** (génère un résumé contextuel prêt à coller dans un e‑mail ou un ticket).【F:sitepulse_FR/modules/ai_insights.php†L1656-L1709】【F:sitepulse_FR/modules/js/sitepulse-ai-insights.js†L815-L892】
- Chaque recommandation accepte désormais une **note personnelle** : saisissez vos commentaires dans le champ dédié, ils sont enregistrés automatiquement via AJAX et synchronisés avec l’export CSV comme avec la copie presse‑papier.【F:sitepulse_FR/modules/ai_insights.php†L1709-L1718】【F:sitepulse_FR/modules/js/sitepulse-ai-insights.js†L894-L937】
- Un retour vocal est fourni via `aria-live` pour confirmer la réussite ou l’échec des exports, copies et sauvegardes de notes, garantissant une utilisation accessible au clavier comme aux lecteurs d’écran.【F:sitepulse_FR/modules/ai_insights.php†L1650-L1665】【F:sitepulse_FR/modules/js/sitepulse-ai-insights.js†L430-L516】

## Installation et configuration
> ⚠️ **Prérequis PHP** : SitePulse requiert PHP 7.1 ou supérieur. Vérifiez la version disponible sur l'hébergement de vos environnements (production, préproduction, staging) avant d'activer l'extension pour éviter toute interruption.【F:sitepulse_FR/sitepulse.php†L6-L35】
1. **Activation** : installez le plugin via l'administration WordPress puis activez-le depuis la page des extensions pour créer le menu « Sitepulse - JLG » dans le tableau de bord.【F:sitepulse_FR/includes/admin-settings.php†L69-L104】
2. **Sélection des modules** : dans *Réglages > SitePulse*, cochez les modules à activer selon vos besoins de surveillance ; seuls les modules sélectionnés ajoutent leurs sous-menus et tâches programmées.【F:sitepulse_FR/includes/admin-settings.php†L233-L366】
3. **Clé Gemini** : saisissez la clé API Google Gemini pour débloquer les analyses IA et profitez des recommandations personnalisées.【F:sitepulse_FR/includes/admin-settings.php†L252-L312】【F:sitepulse_FR/modules/ai_insights.php†L10-L69】
4. **Alertes** : définissez le seuil d'alerte CPU, la fenêtre anti-spam, les canaux (e-mail, webhook) et les destinataires pour contrôler la fréquence des notifications.【F:sitepulse_FR/includes/admin-settings.php†L165-L205】【F:sitepulse_FR/modules/error_alerts.php†L6-L140】
5. **Mode debug et nettoyage** : activez le mode debug pour exposer le sous-menu de diagnostic, vider le journal de debug ou réinitialiser les données directement depuis la même page de réglages.【F:sitepulse_FR/includes/admin-settings.php†L98-L144】【F:sitepulse_FR/includes/admin-settings.php†L1363-L2089】

### Droits d'accès et capacités
- **Capacité dédiée** : SitePulse vérifie systématiquement la capacité renvoyée par `sitepulse_get_capability()` (filtrable via `sitepulse_required_capability`) afin de protéger le menu principal, les sous-menus et les écrans de réglages.【F:sitepulse_FR/includes/admin-settings.php†L12-L101】【F:sitepulse_FR/modules/resource_monitor.php†L8-L34】
- **Activation** : lors de l'activation du plugin, cette capacité est automatiquement ajoutée au rôle `administrator`, garantissant l'accès immédiat à l'interface.【F:sitepulse_FR/sitepulse.php†L1888-L1899】
- **Rôles personnalisés** : pour déléguer la gestion à un rôle sur mesure, ajoutez la capacité filtrée sur un hook (exemple ci-dessous avec une capacité `manage_sitepulse`).

  ```php
  add_filter('sitepulse_required_capability', function () {
      return 'manage_sitepulse';
  });

  add_action('init', function () {
      if ($role = get_role('gestionnaire_sitepulse')) {
          $role->add_cap('manage_sitepulse');
      }
  });
  ```

## Intégrations et automatisations
- **Planification de la disponibilité** : le module de suivi programme un cron horaire (`wp_schedule_event`) pour exécuter `sitepulse_run_uptime_check`, conservant un historique glissant de 30 points et consignant les incidents.【F:sitepulse_FR/modules/uptime_tracker.php†L28-L128】【F:sitepulse_FR/modules/uptime_tracker.php†L2203-L2290】
- **Boucle d'alertes automatisées** : un cron personnalisé toutes les cinq minutes orchestre la lecture du fichier `debug.log` et la surveillance de la charge CPU, avec verrouillage par transient pour éviter le spam d'e-mails.【F:sitepulse_FR/modules/error_alerts.php†L6-L140】【F:sitepulse_FR/modules/error_alerts.php†L560-L720】
- **Query Monitor** : si l'extension est présente, SitePulse expose un collecteur dédié affichant les dernières mesures de temps de chargement et de disponibilité directement dans Query Monitor.【F:sitepulse_FR/includes/integrations.php†L4-L29】
- **API REST du journal** : l’endpoint authentifié `sitepulse/v1/logs/recent` retourne les lignes récentes de `debug.log` avec métadonnées (lecture tronquée, taille, timestamp), regroupements par sévérité et statut dominant pour alimenter Grafana, Datadog ou Loki sans quitter WordPress.【F:sitepulse_FR/modules/log_analyzer.php†L1-L360】
- **API REST Ressources** : l’endpoint sécurisé `sitepulse/v1/resources/history` expose l’historique CPU/RAM/stockage, les derniers instantanés mis en cache, ainsi que les seuils actifs pour alimenter des tableaux de bord externes (Grafana, Looker) ou déclencher des alertes dans vos outils d’observabilité.【F:sitepulse_FR/modules/resource_monitor.php†L20-L360】

## Maintenance
- **Mode debug** : en plus du logging détaillé, un tableau de bord dédié récapitule l'environnement, les crons SitePulse et le journal actif pour faciliter le diagnostic.【F:sitepulse_FR/includes/admin-settings.php†L367-L420】【F:sitepulse_FR/sitepulse.php†L308-L420】
- **Nettoyage et désinstallation** : les réglages permettent de purger journaux et données, tandis que la routine `uninstall.php` supprime options, transients, tâches planifiées et fichiers de log en toute sécurité lors de la suppression du plugin.【F:sitepulse_FR/includes/admin-settings.php†L333-L366】【F:sitepulse_FR/uninstall.php†L14-L200】
- **Pour aller plus loin** : consultez chaque module dans `sitepulse_FR/modules/` pour comprendre les métriques collectées, les interfaces générées et adapter vos interventions si besoin.【F:sitepulse_FR/modules/custom_dashboards.php†L20-L146】

## Rotation du secret des analyses IA
- **Depuis l’interface** : rendez-vous dans *Réglages > SitePulse > IA*, ouvrez la carte « Secret des tâches IA » puis cliquez sur « Régénérer le secret » pour générer immédiatement une nouvelle valeur protégée par un nonce et la capacité `sitepulse_get_capability()`.【F:sitepulse_FR/includes/admin-settings.php†L214-L262】【F:sitepulse_FR/includes/admin-settings.php†L2848-L2886】
- **En ligne de commande** : exécutez `wp sitepulse ai secret regenerate` afin de créer un nouveau secret et l’enregistrer en base, ce qui permet aux opérateurs sans accès GUI de réaliser la rotation depuis un terminal sécurisé.【F:sitepulse_FR/modules/ai_insights.php†L235-L262】【F:sitepulse_FR/modules/ai_insights.php†L1149-L1175】

## Sécurisation du journal de debug
- **Détection et relocalisation automatiques** : SitePulse détecte les serveurs qui ignorent les protections `.htaccess`/`web.config` (Nginx, Caddy, Lighttpd, etc.) et bascule le journal vers `dirname(ABSPATH)/sitepulse/sitepulse-debug.log` lorsqu'il reste dans le webroot après filtrage, en créant le dossier si possible.【F:sitepulse_FR/sitepulse.php†L330-L379】
- **Avertissement explicite en cas d'échec** : si le déplacement hors webroot échoue (permissions insuffisantes, racine inaccessible, etc.), SitePulse écrit un avertissement dans le journal PHP et programme une notice d'administration afin d'inviter l'utilisateur à personnaliser `sitepulse_debug_log_base_dir` ou à renforcer le blocage côté serveur.【F:sitepulse_FR/sitepulse.php†L1360-L1434】
- **Test manuel Nginx** : sur un serveur Nginx vierge, activez le mode debug, vérifiez que `wp-content/uploads/sitepulse/` reste vide et que le fichier `sitepulse-debug.log` est bien créé dans `dirname(ABSPATH)/sitepulse/`; assurez-vous ensuite qu'il n'est plus téléchargeable publiquement (ex. `curl -I https://exemple.test/sitepulse-debug.log` doit renvoyer 404/403).【F:sitepulse_FR/sitepulse.php†L330-L379】
- **Rétention des archives** : par défaut, SitePulse conserve les cinq derniers fichiers `sitepulse-debug.log.*` générés lors de la rotation et supprime le surplus. Ajustez la constante `SITEPULSE_DEBUG_LOG_RETENTION` (ou le filtre `sitepulse_debug_log_retention`) pour modifier cette limite, voire la désactiver en la fixant à `-1`.【F:sitepulse_FR/sitepulse.php†L361-L378】【F:sitepulse_FR/sitepulse.php†L1468-L1477】
- **Localisation personnalisée** : vous pouvez toujours définir un emplacement dédié via le filtre `sitepulse_debug_log_base_dir` (troisième argument = support serveur) pour pointer vers un volume applicatif ou un stockage centralisé.【F:sitepulse_FR/sitepulse.php†L333-L347】

  ```php
  add_filter('sitepulse_debug_log_base_dir', function ($base_dir) {
      return '/var/log/sitepulse'; // répertoire hors webroot, accessible en écriture
  });
  ```

  Assurez-vous de créer le dossier cible avec les bonnes permissions si WordPress ne peut pas le faire automatiquement.【F:sitepulse_FR/sitepulse.php†L372-L378】

## Personnalisation visuelle
- Trois presets CSS prêts à l’emploi (`soft-mint`, `midnight`, `contrast`) complètent la présentation WordPress d’origine. Activez-les via le filtre `sitepulse_active_css_preset` pour harmoniser l’interface SitePulse, le widget d’administration et le bloc Gutenberg « Dashboard preview ».【F:sitepulse_FR/includes/appearance-presets.php†L6-L123】【F:sitepulse_FR/modules/css/appearance-presets.css†L1-L208】
- Consultez le guide [docs/css-presets.md](docs/css-presets.md) pour obtenir des exemples de bascule, appliquer un preset par bloc et créer vos variantes sur mesure.【F:docs/css-presets.md†L1-L72】

## Tests
Un harnais PHPUnit/WP-Unit est disponible dans `tests/phpunit/` (configuré via `phpunit.xml.dist`) afin de valider les modules clefs : suivi d'uptime, notices de debug, nettoyage des transients ainsi que l'analyse du journal d'erreurs (pointeurs de lecture, détection des fatals et verrou de cooldown).【F:tests/phpunit/test-error-alerts.php†L1-L200】

1. Installez la bibliothèque de tests WordPress et définissez la variable d'environnement `WP_TESTS_DIR` (par exemple via `bin/install-wp-tests.sh <db-name> <db-user> <db-pass>`).
2. Assurez-vous que `phpunit` est disponible sur votre machine (ou utilisez un binaire local tel que `vendor/bin/phpunit`). Vous pouvez l'ajouter au projet à l'aide de Composer :

   ```bash
   composer global require phpunit/phpunit ^9.6
   ```

   ou en plaçant le PHAR officiel dans `~/bin/phpunit` puis en l'ajoutant à votre `PATH`.
3. Exécutez les tests depuis la racine du dépôt :

   ```bash
   phpunit
   ```

Ce flux peut être réutilisé en CI pour éviter les régressions sur les fonctionnalités critiques.【F:tests/phpunit/test-uptime-tracker.php†L1-L200】

## Filtres disponibles
- `sitepulse_uptime_request_args` : ajuste les arguments passés à `wp_remote_get()` lors de la vérification d'uptime. Peut être utilisé pour désactiver `sslverify`, modifier le `timeout` ou définir une clé `url` pointant vers une adresse de test dédiée.【F:sitepulse_FR/modules/uptime_tracker.php†L1020-L1109】
- `sitepulse_debug_log_base_dir` : permet de modifier le répertoire racine qui accueillera `sitepulse-debug.log` afin de le déplacer hors du webroot ou vers un volume dédié (le troisième argument indique si le serveur gère les protections).【F:sitepulse_FR/sitepulse.php†L333-L347】
- `sitepulse_debug_log_retention` : ajuste le nombre maximal d'archives `sitepulse-debug.log.*` conservées après rotation (5 par défaut via `SITEPULSE_DEBUG_LOG_RETENTION`, `-1` pour ne pas purger automatiquement).【F:sitepulse_FR/sitepulse.php†L361-L378】【F:sitepulse_FR/sitepulse.php†L1468-L1477】
- `sitepulse_server_protection_file_support` : ajuste la détection automatique des serveurs compatibles (`supported`, `unsupported`, `unknown`) pour forcer ou désactiver la relocalisation des journaux.【F:sitepulse_FR/sitepulse.php†L333-L347】【F:sitepulse_FR/sitepulse.php†L1416-L1434】
- `sitepulse_plugin_dir_size_threshold` : modifie le seuil déclenchant la mise en file d'attente du calcul d'un répertoire d'extension. Par défaut, les dossiers au-delà de 100 Mo sont traités en tâche de fond, mais vous pouvez aussi fixer une limite de fichiers (`max_files`) ou ajuster le quota disque (`max_bytes`).【F:sitepulse_FR/modules/plugin_impact_scanner.php†L531-L611】
- `sitepulse_alert_interval_allowed_values` : expose la liste des intervalles (en minutes) disponibles pour les alertes et permet d’ajouter des paliers (1, 2, 5…120).【F:sitepulse_FR/includes/functions.php†L2297-L2333】
- `sitepulse_alert_interval_smart_value` : permet de surcharger la valeur calculée par le moteur « smart » qui se base sur l’activité récente (fatals, criticités, périodes calmes). Les fenêtres et cibles peuvent également être ajustées via `sitepulse_alert_interval_activity_window`, `sitepulse_alert_interval_activity_max_events`, `sitepulse_alert_interval_smart_*` et `sitepulse_alert_interval_activity_fresh_event_tolerance` pour affiner la priorisation.【F:sitepulse_FR/includes/functions.php†L2297-L2795】
- `sitepulse_transient_delete_batch_size` : ajuste la taille des lots lors d’une purge de transients par préfixe afin d’équilibrer performance et charge SQL.【F:sitepulse_FR/includes/functions.php†L12-L120】
- `sitepulse_ai_models_cache_ttl` : personnalise la durée de vie du catalogue de modèles IA en cache (désactivation possible via `sitepulse_ai_models_enable_cache`).【F:sitepulse_FR/includes/functions.php†L250-L325】

## Pistes d'amélioration

Pour continuer à enrichir SitePulse, voici des axes d'évolution suggérés :

- **Assistants d'onboarding modulaires** : proposer un assistant pas-à-pas qui détecte l'hébergement, pré-remplit les seuils et suggère les modules pertinents pour accélérer la mise en service.
- **Connecteurs d'alertes supplémentaires** : ajouter des intégrations natives avec Slack, Microsoft Teams ou Mattermost afin de diffuser les alertes critiques là où les équipes collaborent déjà.
- **Tableaux de bord programmables** : exposer une API REST/JS dédiée permettant aux intégrateurs de créer des widgets personnalisés (par exemple un score Core Web Vitals) tout en respectant le système de permissions.
- **Surveillance de la chaîne de build** : relier les scans de performances à des webhooks CI/CD pour déclencher automatiquement des audits lors des déploiements, avec un diff synthétique entre deux versions.
- **Rapports exportables** : générer automatiquement un rapport PDF ou Markdown hebdomadaire regroupant uptime, performances, incidents et recommandations IA pour faciliter le partage avec les clients.

Ces propositions visent à renforcer l'adoption du plugin, améliorer la collaboration entre équipes et offrir davantage de visibilité sur l'état des sites surveillés.
