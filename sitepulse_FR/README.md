# Sitepulse - JLG

**Contributors:** jeromelegousse  
**Tags:** performance, monitoring, speed, database, server

Monitors your WordPress site's speed, database, maintenance, server, and errors with modular, toggleable tools.

## Table des matières

1. [Description](#description)
2. [Installation](#installation)
3. [Diagnostic « Santé du site »](#diagnostic-santé-du-site)
4. [Exporter et partager les recommandations IA](#exporter-et-partager-les-recommandations-ia)
5. [Sécuriser la clé API Gemini](#sécuriser-la-clé-api-gemini)
6. [Panorama des modules](#panorama-des-modules)
7. [Workflow de monitoring](#workflow-de-monitoring)
8. [Sécurité, audit et conformité](#sécurité-audit-et-conformité)
9. [WordPress Compatibility](#wordpress-compatibility)
10. [License](#license)
11. [Changelog](#changelog)
12. [Upgrade Notice](#upgrade-notice)

## Description

Sitepulse - JLG takes the pulse of your WordPress site, offering modules for:

- Speed analysis (load times, server processing time)
- Database optimization (clean bloat, suggest indexes)
- Server monitoring (CPU, memory, uptime) with programmable maintenance windows that pause alerts and keep an internal audit trail
- Error logging and alerts (email plus Slack, Microsoft Teams, and Discord webhooks with native formatting)
- Plugin impact analysis
- Maintenance checks and AI insights
- Custom dashboards and multisite support
- Customisable thresholds for speed alerts, uptime targets and revision cleanup suggestions
- Cleanup history widget surfacing transient purge statistics directly in the settings page and WordPress dashboard
- Site Health integration surfacing SitePulse alerts and AI requirements
- Accessible sharing tools for AI recommendations (CSV export, clipboard copy, contextual notes)

### Key performance defaults

- **Alerte de vitesse (avertissement / critique)** : 200 ms / 500 ms tant que vous n’avez pas défini vos propres seuils.
- **Disponibilité minimale avant alerte** : 99 % par défaut, ajustable au dixième de point près.
- **Limite de révisions recommandée** : 100 révisions avant que SitePulse ne signale un nettoyage.

Ces valeurs peuvent être modifiées dans la page « Réglages » de SitePulse. Elles sont automatiquement utilisées par les modules concernés (analyseur de vitesse, tableaux de bord personnalisés, vérification de base de données) tout en conservant les anciennes valeurs si elles existent déjà.

Toggle modules in the admin panel to keep it lightweight. Includes debug mode and cleanup options.

## Panorama des modules

| Module | Objectif | Fonctionnalités clés |
| --- | --- | --- |
| **Speed Analyzer** | Mesurer la performance front-end | Scans manuels et planifiés, agrégation mobile/desktop, recommandations contextualisées, budgets de vitesse personnalisables |
| **Database Optimizer** | Nettoyer et optimiser la base | Purge des révisions/transients, historique des opérations, seuils ajustables et notifications de nettoyage |
| **Uptime Tracker** | Surveiller la disponibilité | Agents multiples avec file d’attente distante normalisée (TTL/limite filtrables), rétention 30-365 jours, export CSV, intégration Site Health, fenêtres de maintenance ciblées par agent |
| **Resource Monitor** | Suivre CPU/RAM/Disque | Snapshots réguliers, historique configurable (90-365 jours), exports JSON/CSV volumineux, alertes visuelles basées sur les seuils |
| **Error Alerts** | Détecter les erreurs PHP/JS | Lecture sécurisée de `debug.log`, webhooks Slack/Teams/Discord, filtrage par gravité, journal d’alertes |
| **AI Insights** | Générer des recommandations | Orchestrateur Gemini avec cache, historique commentable, export CSV/clipboard, module de notes collaboratif |
| **Plugin Impact Scanner** | Évaluer l’effet des extensions | Mesures de temps de chargement, poids disque, filtres multi-critères, scénarios d’atténuation |
| **Maintenance Advisor** | Planifier les mises à jour | Synthèse des mises à jour, intégration Thickbox pour changelog, recommandations de maintenance pilotées |
| **Custom Dashboards** | Construire des vues dédiées | Widgets drag & drop, préférences par utilisateur, intégration KPI modules, partage interne |

Chaque module peut être activé/désactivé depuis l’interface d’administration pour n’installer que les briques nécessaires à votre contexte. Les hooks `sitepulse_module_enabled` / `sitepulse_module_disabled` permettent d’auditer ces actions ou d’automatiser le provisionnement.

## Workflow de monitoring

1. **Instrumentation** : installez SitePulse, activez les modules pertinents et définissez vos seuils de vitesse, uptime et ressources dans la page Réglages.
2. **Collecte** : planifiez des scans récurrents (cron WP ou Action Scheduler) et connectez vos webhooks incidentiels (Slack, Teams, Discord) pour centraliser les alertes.
3. **Analyse** : consultez le tableau de bord personnalisé, les rapports IA et les historiques de performance pour prioriser les actions. Les métadonnées exposées par les fonctions `sitepulse_get_recent_log_lines()` et `sitepulse_get_speed_thresholds()` facilitent les corrélations entre modules.
4. **Remédiation** : déclenchez les actions de nettoyage, les scripts de maintenance ou les escalades depuis les modules concernés. Les événements sont historisés dans les options du plugin afin d’assurer un audit trail minimum.
5. **Partage** : exportez les rapports CSV/PDF, copiez les recommandations IA contextualisées et partagez les liens d’incident pour garder vos équipes alignées.

### Gérer l’historique des ressources

Le module Resource Monitor enregistre désormais ses relevés dans une table dédiée afin de conserver plusieurs mois de tendance.
Dans **Réglages → Modules → Seuils du moniteur de ressources**, vous pouvez :

- choisir la durée de conservation (90, 180 ou 365 jours). Les entrées plus anciennes sont purgées automatiquement dès qu’un nouveau snapshot est stocké ;
- fixer le nombre maximal de lignes incluses dans un export CSV/JSON. Renseignez `0` pour autoriser un export illimité lorsque vous devez analyser un historique complet.

Ces paramètres sont également filtrables via `sitepulse_resource_monitor_allowed_retention_days` et `sitepulse_resource_monitor_export_rows_ceiling` pour harmoniser la politique de rétention sur plusieurs sites.

## Provisionner des agents de surveillance

Les déploiements multi-sites peuvent piloter la liste des agents d’uptime depuis le code :

- Filtrez les données persistées avec `sitepulse_uptime_sanitized_agents` ou écoutez l’action `sitepulse_uptime_agents_prepared` pour synchroniser une configuration centralisée.
- Ajustez dynamiquement les définitions exposées côté lecture via `sitepulse_uptime_agents`, y compris depuis un MU-plugin.
- Forcez l’activation ou le poids d’un agent grâce aux filtres `sitepulse_uptime_agent_is_active` et `sitepulse_uptime_agent_weight` afin d’orchestrer des régions prioritaires.
- Interceptez l’orchestration de jobs distants avec `sitepulse_uptime_pre_enqueue_job` (filtre) et `sitepulse_uptime_job_enqueued` (action) pour tracer les vérifications ou injecter des métadonnées spécifiques.

```php
add_filter('sitepulse_uptime_sanitized_agents', function (array $agents) {
    $agents['paris'] = [
        'label'  => 'Paris (FR)',
        'region' => 'eu-fr',
        'url'    => 'https://paris.example.com/health',
        'weight' => 2.0,
        'active' => true,
    ];

    return $agents;
});

add_filter('sitepulse_uptime_agent_weight', function ($weight, $agent_id) {
    return $agent_id === 'backup_dc' ? 0.5 : $weight;
}, 10, 2);
```

Les métriques d’uptime exposées via l’écran d’administration, l’API REST (`/sitepulse/v1/uptime/remote-queue`) et l’export CSV incluent désormais les pondérations et ignorent automatiquement les agents désactivés.

## Sécurité, audit et conformité

- **Gestion des secrets** : définissez `SITEPULSE_GEMINI_API_KEY` dans `wp-config.php` ou via le filtre `sitepulse_gemini_api_key` pour éviter de stocker les clés en clair.
- **Rétention contrôlée** : ajustez les durées de conservation des historiques (uptime, ressources, logs) grâce aux options du plugin ou en branchant vos propres stratégies via hooks (`sitepulse_resource_monitor_history_retention`).
- **Journalisation** : les modules exposent des actions (`do_action`) lors des purges, scans ou envoi d’alertes. Exploitez-les pour alimenter vos systèmes SIEM (ELK, Datadog) et tracer les opérations sensibles.
- **Accessibilité** : les interfaces intègrent `aria-live`, états de focus et messages contextuels pour s’aligner sur les bonnes pratiques WCAG 2.1 AA. Activez le mode debug pour vérifier les annonces vocales et l’état des composants.

Ces garde-fous rapprochent SitePulse des exigences enterprise sans sacrifier la simplicité d’installation WordPress.

## Installation

1. Upload `sitepulse-jlg.zip` to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Visit 'SitePulse' in your admin menu to configure the modules.

## Diagnostic « Santé du site »

SitePulse enregistre désormais deux tests visibles dans l’outil « Santé du site » de WordPress :

- **État de SitePulse** récapitule les avertissements WP-Cron et les erreurs critiques générées par l’IA, en les classant selon leur gravité.
- **Clé API Gemini SitePulse** vérifie que le module AI Insights dispose d’une clé API prête à l’emploi avant de lancer des analyses.

Ces tests facilitent la détection proactive des problèmes susceptibles d’empêcher l’exécution des tâches planifiées ou des analyses IA.

## Exporter et partager les recommandations IA

Depuis la page **Analyses par IA**, la section « Historique des recommandations » répertorie les réponses générées et propose plusieurs actions accessibles :

- Filtrez les recommandations par modèle ou limitation avant d’exporter.
- Cliquez sur **Exporter en CSV** pour télécharger un fichier structuré (format UTF-8, séparateur « ; ») contenant la date, le modèle, la limitation, le texte et vos éventuelles notes personnelles.
- Cliquez sur **Copier** pour envoyer le même contenu (y compris les notes) dans le presse-papiers. SitePulse prépare automatiquement un texte brut et utilise l’API du presse-papiers quand elle est disponible, avec une annonce via `aria-live` pour les lecteurs d’écran.
- Chaque élément d’historique propose un champ « Note personnelle ». Les commentaires saisis sont enregistrés en base via une option dédiée et sont inclus dans les exports ultérieurs.

Les actions d’export et de copie prennent en compte vos filtres actifs : seules les recommandations visibles sont partagées. Un message de confirmation accessible informe systématiquement du résultat (succès ou erreur) pour garantir une expérience inclusive.

## Sécuriser la clé API Gemini

Pour éviter de stocker votre clé API Gemini dans la base de données, vous pouvez la définir directement dans `wp-config.php` :

```php
define('SITEPULSE_GEMINI_API_KEY', 'votre-cle-secrete');
```

Lorsque cette constante est présente, SitePulse utilise automatiquement cette valeur, désactive le champ de saisie dans l’interface d’administration et ignore les tentatives d’enregistrement. Vous pouvez également fournir la clé dynamiquement via le filtre `sitepulse_gemini_api_key` si vous la récupérez depuis un gestionnaire de secrets :

```php
add_filter('sitepulse_gemini_api_key', function () {
    return getenv('SITEPULSE_GEMINI_API_KEY');
});
```

Dans les deux cas, aucune donnée sensible n’est conservée dans la base WordPress.

## WordPress Compatibility

- Requires at least: 5.0
- Tested up to: 6.6
- Stable tag: 1.0

## License

GPLv2 or later  
[http://www.gnu.org/licenses/gpl-2.0.html](http://www.gnu.org/licenses/gpl-2.0.html)

## Changelog

### 1.0

- Initial release with all core modules.

## Upgrade Notice

### 1.0

- First version—full pulse-monitoring suite!

