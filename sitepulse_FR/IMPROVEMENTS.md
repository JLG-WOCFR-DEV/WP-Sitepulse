# Plan d'amélioration des fonctions

Ce document répertorie les fonctions de SitePulse qui gagneraient à être alignées sur les standards observés dans les solutions professionnelles de monitoring WordPress/SaaS. Les propositions tiennent compte des attentes en matière de résilience, d'observabilité et d'expérience utilisateur premium.

## Priorités de développement recommandées

1. **Rapports SLA multi-agents** : capitaliser sur l’historique conservé entre 30 et 365 jours pour automatiser des exports SLA (CSV/PDF) et des tableaux de bord consolidés, plutôt que de simplement tronquer les journaux après rétention.【F:sitepulse_FR/modules/uptime_tracker.php†L2404-L2459】
2. **Alerting multi-canal et escalade** : enrichir le moteur d’alertes au-delà du duo e-mail/webhook afin de prendre en charge des destinations incident management (Opsgenie, PagerDuty, SMS) avec suivi d’accusé de réception et modes d’escalade.【F:sitepulse_FR/modules/error_alerts.php†L320-L356】
3. **Orchestration IA instrumentée** : fiabiliser la planification (persistence des jobs, retries, coût/quota journalisé) plutôt que de se reposer exclusivement sur Action Scheduler ou WP-Cron sans télémétrie dédiée.【F:sitepulse_FR/modules/ai_insights.php†L901-L959】
4. **Agrégations Resource Monitor** : compléter l’endpoint REST paginé (288 points par défaut) par des séries agrégées, des moyennes glissantes et des exports programmés adaptés aux analyses longue durée.【F:sitepulse_FR/modules/resource_monitor.php†L424-L520】

## `sitepulse_delete_transients_by_prefix()`

- **Statut :** ✅ Support du cache persistant (groupes `transient`/`site-transient`), purge en lots, télémétrie via les hooks `sitepulse_transient_deletion_batch`/`completed` (avec indication du scope) et historisation des purges exposée dans les réglages ainsi qu’un widget du tableau de bord WordPress.【F:sitepulse_FR/includes/functions.php†L12-L260】【F:sitepulse_FR/includes/admin-settings.php†L3208-L3268】
- **Pistes pro :**
  - 🔭 Permettre une purge asynchrone via Action Scheduler ou queue REST pour les installations multi-millions d'options.

## `sitepulse_get_recent_log_lines()`

- **Statut :** ✅ Ajout d’un verrouillage partagé, d’un mode métadonnées (`lines`, `bytes_read`, `truncated`, `last_modified`) et d’un indicateur de troncature exploité dans l’UI pour informer les utilisateurs.【F:sitepulse_FR/includes/functions.php†L320-L520】【F:sitepulse_FR/modules/log_analyzer.php†L1-L200】
- **Pistes pro :**
  - ✅ Ajouter une API REST pour exposer ces métadonnées aux outils externes (Grafana Loki, Datadog Live Tail) via l’endpoint sécurisé `sitepulse/v1/logs/recent` (filtrage par niveaux, statut dominant, méta enrichies).【F:sitepulse_FR/modules/log_analyzer.php†L1-L360】
  - 🔭 Déporter la lecture sur `SplFileObject` en streaming pour lire des fichiers >100 Mo sans concaténation mémoire.

## `sitepulse_get_ai_models()`

- **Statut :** ✅ Cache runtime + transitoire avec TTL filtrable, validation de la longueur des clés et exposition d’un hook `sitepulse_ai_models_sanitized` pour harmoniser les catalogues dynamiques.【F:sitepulse_FR/includes/functions.php†L250-L325】
- **Pistes pro :**
  - 🔭 Stocker les métadonnées enrichies (coût, région, quota) dans une table personnalisée ou un CPT synchronisé périodiquement.
  - 🔭 Ajouter une interface d’administration pour activer/désactiver les modèles par site/rôle et contrôler la dette budgétaire IA.

## `sitepulse_sanitize_alert_interval()`

- **Statut :** ✅ Ouverture à des paliers 1-120 min, mode « smart » piloté par filtre et harmonisation avec le cron dynamique du module d’alertes.【F:sitepulse_FR/includes/functions.php†L2799-L2855】【F:sitepulse_FR/modules/error_alerts.php†L1277-L1335】
- **Pistes pro :**
  - ✅ Implémentation d’un calcul « smart » natif qui raccourcit l’intervalle après des erreurs critiques/fatales et l’étire automatiquement après une période calme, avec télémétrie persistée sur les exécutions et alertes envoyées.【F:sitepulse_FR/includes/functions.php†L2297-L2795】【F:sitepulse_FR/modules/error_alerts.php†L1310-L1716】
  - 🔭 Permettre des intervalles différenciés par canal (webhook vs e-mail) ou par type de signalement.

## `sitepulse_get_speed_thresholds()`

- **Statut :** ✅ Gestion multi-profils (mobile/desktop), corrections journalisées via `sitepulse_speed_threshold_corrected` et alignement sur les audits lorsque les seuils sont incohérents.【F:sitepulse_FR/includes/functions.php†L180-L260】
- **Pistes pro :**
  - 🔭 Introduire des profils Core Web Vitals (LCP, CLS, INP) avec pondération par type de trafic.
  - 🔭 Historiser les corrections dans un log optionnel pour afficher les ajustements directement dans l’UI (timeline de tuning).

- **Constat :** le module gère désormais plusieurs agents (`SITEPULSE_OPTION_UPTIME_AGENTS`), normalise la file d’attente distante (TTL filtrable, taille maximum configurable, déduplication) et conserve une rétention configurable entre 30 et 365 jours. Les métriques de file (compteurs de purge, backlog moyen/maxi, prochain déclenchement) sont historisées dans `SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE_METRICS`, restituées dans l’interface et exposées via l’endpoint REST sécurisé `sitepulse/v1/uptime/remote-queue` (statut, alertes, prochains déclenchements) pour alimenter des tableaux de bord externes.【F:sitepulse_FR/modules/uptime_tracker.php†L277-L346】【F:sitepulse_FR/modules/uptime_tracker.php†L724-L888】【F:sitepulse_FR/modules/uptime_tracker.php†L968-L1228】【F:sitepulse_FR/modules/uptime_tracker.php†L2864-L3158】
- **Pistes pro :**
  - Générer des rapports SLA mensuels (CSV/PDF) agrégeant tous les agents et intégrant les fenêtres de maintenance (`sitepulse_uptime_get_agents()` + annotations) pour rivaliser avec Pingdom/Better Uptime.
  - Exposer les métriques instrumentées via un widget d’administration, l’API REST ou des notifications lorsqu’une dérive (`delayed_jobs`, `max_wait_seconds`) est détectée.
  - Ajouter des canaux d’alerte temps réel (webhooks dédiés, SMS) et une page de statut publique afin de se rapprocher des offres premium.

## Module « Resource Monitor »

- **Constat :** le module calcule un instantané des ressources et des avertissements, mais n'enregistre ni séries temporelles ni corrélations avec les événements, contrairement à New Relic ou Datadog qui stockent des métriques à haute fréquence pour établir des tendances et des alertes adaptatives.【F:sitepulse_FR/modules/resource_monitor.php†L131-L218】【F:sitepulse_FR/modules/resource_monitor.php†L305-L378】
- **Pistes pro :**
  - Introduire une persistance longue durée (Custom Post Type ou table dédiée) pour suivre l'évolution CPU/RAM/disque, avec export JSON/CSV.
  - ✅ Fournir des dashboards corrélant les seuils personnalisés aux pics (heatmaps, alerting basé sur la dérive) et une API REST pour l'intégration Grafana via `sitepulse/v1/resources/history` (filtrage temporel, résumés, instantanés, seuils actifs).

## Module « AI Insights »

- **Constat :** l'orchestrateur Gemini gère les erreurs de quota et le cache transitoire, mais ne propose pas de file d'attente asynchrone ni d'évaluation automatique des recommandations, contrairement aux suites d'assistance IA (ContentKing, Surfer) qui priorisent les analyses et mesurent l'impact sur les KPI.【F:sitepulse_FR/modules/ai_insights.php†L1606-L1759】【F:sitepulse_FR/modules/js/sitepulse-ai-insights.js†L430-L516】
- **Pistes pro :**
  - Ajouter une file d'attente (Action Scheduler, Jobs WP-Cron) pour lisser les demandes IA, relancer automatiquement les échecs et enregistrer le coût par requête.
  - Calculer un score d'impact basé sur l'historique des actions (TTFB, conversions) et afficher des priorités avec un suivi « fait / à faire ».
  - Exposer une intégration Zapier/Make pour pousser les recommandations dans Jira, Linear ou Slack.

## Dashboards personnalisés

- **Constat :** les préférences sont stockées par utilisateur et les cartes nécessitent un module actif, mais l'interface reste limitée au back-office WordPress, sans partage ni versioning collaboratif comme dans des solutions telles que Looker ou Datadog Dashboards.【F:sitepulse_FR/modules/custom_dashboards.php†L20-L145】【F:sitepulse_FR/modules/custom_dashboards.php†L260-L296】【F:sitepulse_FR/modules/custom_dashboards.php†L388-L466】
- **Pistes pro :**
  - Permettre la publication de vues « lecture seule » partageables (URL signée, iframe, PDF) et la duplication de layouts entre sites.
  - Ajouter des rôles de lecture/édition, un historique des modifications et une synchronisation multisite pour homogénéiser les tableaux de bord.
  - Brancher des sources externes (APM, Lighthouse) et proposer des widgets conditionnels pour une expérience type observability suite.

## Module « Plugin Impact Scanner »

- **Constat :** la page admin charge les plugins actifs, applique des seuils statiques et restitue un instantané local des mesures (impact moyen, poids disque) sans tendance ni segmentation par environnement, loin des analyses corrélées au trafic offertes par New Relic APM ou ManageWP Performance.【F:sitepulse_FR/modules/plugin_impact_scanner.php†L28-L133】【F:sitepulse_FR/modules/plugin_impact_scanner.php†L222-L360】
- **Statut :** ✅ Historique roulant (30 jours) et tendances comparatives sont désormais persistés côté tracker, affichés dans l’UI (variation vs précédente mesure, moyennes 7 j/30 j) et inclus dans l’export CSV pour documenter les régressions de performance.【F:sitepulse_FR/includes/plugin-impact-tracker.php†L1-L210】【F:sitepulse_FR/modules/plugin_impact_scanner.php†L520-L700】【F:sitepulse_FR/modules/js/plugin-impact-scanner.js†L1-L320】
- **Pistes pro :**
  - Ajouter un historique des temps de chargement (rolling 7/30 jours) et des comparaisons pré/post mise à jour pour identifier les régressions de performance.
  - Pondérer l’impact par type de requête (admin/public/REST) et par environnement (staging vs production) afin d’imiter les vues multi-contextes des suites APM.
  - Offrir des exports programmés (CSV/API REST) et une intégration Slack pour rapprocher l’outil des workflows d’équipes produit.

## Module « Maintenance Advisor »

- **Constat :** l’écran récapitule les mises à jour core/plugins/thèmes et leurs attributs (auto-update, changelog via Thickbox) mais reste centré sur une action manuelle ponctuelle, sans orchestration type patch management proposée par MainWP ou WP Umbrella.【F:sitepulse_FR/modules/maintenance_advisor.php†L1-L146】【F:sitepulse_FR/modules/maintenance_advisor.php†L182-L246】
- **Pistes pro :**
  - Introduire des fenêtres de maintenance planifiées avec approbation et génération automatique de rapports après déploiement.
  - Connecter les statuts d’auto-update à des politiques (ex : désactiver si audit échoue) et proposer un mode bulk update sandboxé.
  - Ajouter une intégration webhook/Slack ou ServiceNow pour notifier les équipes Ops comme le font les solutions MSP.

## Module « Database Optimizer »

- **Constat :** le nettoyage des révisions/transients se déclenche à la demande (lot de 500 révisions, notices instantanées) et se limite aux tables natives, sans planification ni suivi de consommation comme WP Rocket ou NitroPack peuvent le proposer.【F:sitepulse_FR/modules/database_optimizer.php†L18-L199】
- **Pistes pro :**
  - Permettre la mise en place de fenêtres de purge récurrentes avec rapports (gain de taille, durée) et alertes si la croissance reprend.
  - Étendre la couverture aux tables personnalisées (WooCommerce sessions, logs) via une couche de déclarations modulaires.
  - Ajouter une estimation avant/après et un scoring d’impact pour prioriser les nettoyages sur plusieurs environnements.

## Module « Speed Analyzer »

- **Constat :** le module propose des scans manuels/planifiés via cron interne, applique des limites de fréquence et expose des seuils statiques, mais ne gère ni profils Core Web Vitals multi-device ni budgets partagés comme les sondes synthétiques de SpeedCurve ou PageSpeed Insights API.【F:sitepulse_FR/modules/speed_analyzer.php†L4-L190】
- **Pistes pro :**
  - Supporter des scénarios Lighthouse multi-origines (mobile/desktop) avec stockage des runs pour afficher tendances et écarts.
  - Définir des budgets par page/groupe et déclencher des alertes proactives (Slack, webhook) quand les seuils sont dépassés.
  - Intégrer des comparaisons concurrentielles (benchmark de 3 sites) pour se rapprocher des offres pro d’analyse de vitesse.

## Module « Error Alerts »

- **Constat :** les contrôles planifiés vérifient la charge CPU et le journal `debug.log`, avec envoi email/webhook conditionnel, mais sans corrélation avec des flux externes ni boucle d’accusé de réception comme dans Datadog Incident Management ou Better Stack.【F:sitepulse_FR/modules/error_alerts.php†L940-L1056】
- **Pistes pro :**
  - Ajouter une hiérarchisation des alertes (critique/avertissement) avec escalade multi-canal et pause après acquittement.
  - Synchroniser les alertes avec des plateformes d’incident (Opsgenie, PagerDuty) et enregistrer les réponses pour bâtir un audit trail.
  - Fournir un historique consultable des événements (qui, quand, quelle réponse) et des dashboards de MTTR pour rivaliser avec les suites SRE.

## Plateforme & intégrations

- **Constat :** les points REST servent surtout à piloter des actions internes (ordonnancement uptime, test d’alertes) sans offrir d’accès complet aux métriques ni d’authentification applicative dédiée.【F:sitepulse_FR/modules/uptime_tracker.php†L112-L168】【F:sitepulse_FR/modules/error_alerts.php†L1430-L1458】 Le stockage des historiques (uptime 30 événements, ressources 288 snapshots/24 h) limite l’export et la corrélation avec les outils d’observabilité d’entreprise.【F:sitepulse_FR/modules/uptime_tracker.php†L2183-L2207】【F:sitepulse_FR/modules/resource_monitor.php†L827-L845】【F:sitepulse_FR/modules/resource_monitor.php†L998-L1023】
- **Pistes pro :**
  - Étendre l’API avec un schéma documenté (OpenAPI, GraphQL) couvrant métriques, incidents et préférences, plus des clés applicatives ou OAuth pour les intégrations tierces.
  - Ajouter des flux d’export programmables (CSV, JSON, webhooks) et un connecteur temps réel (EventBridge, Kafka, WebSub) pour alimenter les SIEM/APM.
  - Permettre la définition de politiques de rétention modulables (90/180/365 jours) et de destinations d’archivage (S3, BigQuery) pour se rapprocher des offres pro.
