# Plan d'amélioration des fonctions

Ce document répertorie les fonctions de SitePulse qui gagneraient à être alignées sur les standards observés dans les solutions professionnelles de monitoring WordPress/SaaS. Les propositions tiennent compte des attentes en matière de résilience, d'observabilité et d'expérience utilisateur premium.

## `sitepulse_delete_transients_by_prefix()`

- **Statut :** ✅ Support du cache persistant (groupes `transient`/`site-transient`), purge en lots et télémétrie via les hooks `sitepulse_transient_deletion_batch`/`completed` pour suivre les nettoyages.【F:sitepulse_FR/includes/functions.php†L12-L120】
- **Pistes pro :**
  - 🔭 Exporter les statistiques de purge vers le tableau de bord (Widget ou admin notice) afin de mettre en avant les transients problématiques.
  - 🔭 Permettre une purge asynchrone via Action Scheduler ou queue REST pour les installations multi-millions d'options.

## `sitepulse_get_recent_log_lines()`

- **Statut :** ✅ Ajout d’un verrouillage partagé, d’un mode métadonnées (`lines`, `bytes_read`, `truncated`, `last_modified`) et d’un indicateur de troncature exploité dans l’UI pour informer les utilisateurs.【F:sitepulse_FR/includes/functions.php†L320-L520】【F:sitepulse_FR/modules/log_analyzer.php†L1-L200】
- **Pistes pro :**
  - 🔭 Déporter la lecture sur `SplFileObject` en streaming pour lire des fichiers >100 Mo sans concaténation mémoire.
  - 🔭 Ajouter une API REST pour exposer ces métadonnées aux outils externes (Grafana Loki, Datadog Live Tail).

## `sitepulse_get_ai_models()`

- **Statut :** ✅ Cache runtime + transitoire avec TTL filtrable, validation de la longueur des clés et exposition d’un hook `sitepulse_ai_models_sanitized` pour harmoniser les catalogues dynamiques.【F:sitepulse_FR/includes/functions.php†L250-L325】
- **Pistes pro :**
  - 🔭 Stocker les métadonnées enrichies (coût, région, quota) dans une table personnalisée ou un CPT synchronisé périodiquement.
  - 🔭 Ajouter une interface d’administration pour activer/désactiver les modèles par site/rôle et contrôler la dette budgétaire IA.

## `sitepulse_sanitize_alert_interval()`

- **Statut :** ✅ Ouverture à des paliers 1-120 min, mode « smart » piloté par filtre et harmonisation avec le cron dynamique du module d’alertes.【F:sitepulse_FR/includes/functions.php†L520-L640】【F:sitepulse_FR/modules/error_alerts.php†L940-L1000】
- **Pistes pro :**
  - 🔭 Implémenter un calcul « smart » natif basé sur les occurrences d’erreurs (délai raccourci après un fatal, augmenté après période calme).
  - 🔭 Permettre des intervalles différenciés par canal (webhook vs e-mail) ou par type de signalement.

## `sitepulse_get_speed_thresholds()`

- **Statut :** ✅ Gestion multi-profils (mobile/desktop), corrections journalisées via `sitepulse_speed_threshold_corrected` et alignement sur les audits lorsque les seuils sont incohérents.【F:sitepulse_FR/includes/functions.php†L180-L260】
- **Pistes pro :**
  - 🔭 Introduire des profils Core Web Vitals (LCP, CLS, INP) avec pondération par type de trafic.
  - 🔭 Historiser les corrections dans un log optionnel pour afficher les ajustements directement dans l’UI (timeline de tuning).

## Module « Uptime Tracker »

- **Constat :** l'historique conserve uniquement les 30 derniers points et un seul agent actif, ce qui limite la profondeur d'analyse et la corrélation multi-région par rapport à des outils comme Pingdom ou Better Uptime qui agrègent plusieurs sondes et publient des SLA détaillés.【F:sitepulse_FR/modules/uptime_tracker.php†L2203-L2290】【F:sitepulse_FR/modules/uptime_tracker.php†L258-L320】
- **Pistes pro :**
  - Permettre la configuration de multiples agents géographiques avec pondération et tests parallèles, puis générer des rapports SLA mensuels exportables.
  - Étendre la fenêtre d'historique (via options et stockage personnalisé) pour autoriser des rétrospectives de 90 jours/12 mois et la corrélation avec les annotations de maintenance.
  - Ajouter des canaux d'alerte temps réel (webhooks dédiés, SMS) et une page de statut publique afin de se rapprocher des offres premium.

## Module « Resource Monitor »

- **Constat :** le module calcule un instantané des ressources et des avertissements, mais n'enregistre ni séries temporelles ni corrélations avec les événements, contrairement à New Relic ou Datadog qui stockent des métriques à haute fréquence pour établir des tendances et des alertes adaptatives.【F:sitepulse_FR/modules/resource_monitor.php†L135-L199】【F:sitepulse_FR/modules/resource_monitor.php†L305-L378】
- **Pistes pro :**
  - Introduire une persistance longue durée (Custom Post Type ou table dédiée) pour suivre l'évolution CPU/RAM/disque, avec export JSON/CSV.
  - Fournir des dashboards corrélant les seuils personnalisés aux pics (heatmaps, alerting basé sur la dérive) et une API REST pour l'intégration Grafana.

## Module « AI Insights »

- **Constat :** l'orchestrateur Gemini gère les erreurs de quota et le cache transitoire, mais ne propose pas de file d'attente asynchrone ni d'évaluation automatique des recommandations, contrairement aux suites d'assistance IA (ContentKing, Surfer) qui priorisent les analyses et mesurent l'impact sur les KPI.【F:sitepulse_FR/modules/ai_insights.php†L1606-L1759】【F:sitepulse_FR/modules/js/sitepulse-ai-insights.js†L430-L516】
- **Pistes pro :**
  - Ajouter une file d'attente (Action Scheduler, Jobs WP-Cron) pour lisser les demandes IA, relancer automatiquement les échecs et enregistrer le coût par requête.
  - Calculer un score d'impact basé sur l'historique des actions (TTFB, conversions) et afficher des priorités avec un suivi « fait / à faire ».
  - Exposer une intégration Zapier/Make pour pousser les recommandations dans Jira, Linear ou Slack.

## Dashboards personnalisés

- **Constat :** les préférences sont stockées par utilisateur et les cartes nécessitent un module actif, mais l'interface reste limitée au back-office WordPress, sans partage ni versioning collaboratif comme dans des solutions telles que Looker ou Datadog Dashboards.【F:sitepulse_FR/modules/custom_dashboards.php†L20-L145】【F:sitepulse_FR/modules/custom_dashboards.php†L260-L276】【F:sitepulse_FR/modules/custom_dashboards.php†L388-L466】
- **Pistes pro :**
  - Permettre la publication de vues « lecture seule » partageables (URL signée, iframe, PDF) et la duplication de layouts entre sites.
  - Ajouter des rôles de lecture/édition, un historique des modifications et une synchronisation multisite pour homogénéiser les tableaux de bord.
  - Brancher des sources externes (APM, Lighthouse) et proposer des widgets conditionnels pour une expérience type observability suite.

