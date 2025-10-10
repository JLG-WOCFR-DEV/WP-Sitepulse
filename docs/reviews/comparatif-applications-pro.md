# Comparatif SitePulse vs applications professionnelles

Ce document synthétise les écarts entre l’expérience actuelle de SitePulse et les standards observés dans des suites professionnelles de monitoring (Better Uptime, Datadog, New Relic). Il met l’accent sur quatre axes : UX/UI, ergonomie, fiabilité perçue et design.

## Synthèse rapide

| Domaine | Référentiel apps pro | Situation actuelle dans SitePulse | Opportunités d’amélioration |
| --- | --- | --- | --- |
| UX / UI | KPI cards, bandeaux dynamiques et timelines d’incidents sur la page d’accueil. | Le tableau de bord affiche déjà des cartes uptime/logs/vitesse avec tendances et bannière de statut, mais la mise en avant reste textuelle et dépend de la configuration manuelle.【F:sitepulse_FR/modules/custom_dashboards.php†L1301-L1499】 | Ajouter une grille de KPI hiérarchisée avec jauges/sparklines et CTA contextualisés pour rapprocher la lecture des consoles Datadog. |
| Ergonomie | Navigation segmentée par persona, recherche instantanée et filtres persistants. | Le sélecteur de modules embarque déjà un champ de recherche et une mémoire locale, mais sans regroupements thématiques.【F:sitepulse_FR/modules/js/sitepulse-dashboard-nav.js†L1-L160】 | Créer des sections (Performance, Sécurité, Maintenance) avec badges d’état et résultats filtrables pour guider les profils Ops/Marketing. |
| Fiabilité | Centres de contrôle affichant ETA, progression et exports planifiés. | La carte « Traitements en arrière-plan » liste les jobs avec journaux détaillés, mais sans ETA, actions rapides ni exports programmés.【F:sitepulse_FR/includes/admin-settings.php†L3570-L3634】 | Calculer les temps moyens pour afficher une ETA, proposer relance/suspension/escale et générer des rapports PDF/CSV automatisés. |
| Design | Design system unifié, mode sombre piloté, visualisations avancées. | Les feuilles de style utilisent déjà des tokens CSS mais restent limitées à des cartes statiques et aux couleurs WordPress.【F:sitepulse_FR/modules/css/custom-dashboard.css†L1-L160】 | Étendre le design system avec composants visuels (gauges, heatmaps), activer un toggler clair/sombre persistant et moderniser l’iconographie. |

## Observations détaillées & pistes

### 1. UX / UI
- **Constat :** Le dashboard assemble des cartes modulaires (uptime, logs, vitesse) avec tendances, badge de statut et bannière incident, mais reste concentré sur des valeurs numériques sans visualisation temps réel.【F:sitepulse_FR/modules/custom_dashboards.php†L1301-L1499】
- **Comparaison pro :** Better Uptime et Datadog affichent des sparklines, une timeline d’incidents et des CTA directs vers les playbooks.
- **Améliorations proposées :**
  - Injecter des micro-visualisations (sparklines SVG basées sur l’historique déjà récupéré) et des jauges de conformité SLA.
  - Étendre la bannière d’état pour intégrer des filtres de période synchronisés avec les endpoints REST (uptime, logs) afin de proposer une vue « 24 h / 7 j / 30 j » uniforme.【F:sitepulse_FR/modules/uptime_tracker.php†L1418-L1498】
  - Ajouter des CTA contextualisés (« Ouvrir le playbook incident », « Planifier un test vitesse ») afin de réduire le temps de réaction.

### 2. Ergonomie
- **Constat :** La navigation JavaScript respecte `prefers-reduced-motion`, mémorise la recherche et gère les boutons précédent/suivant, mais ne segmente pas encore les modules par usage.【F:sitepulse_FR/modules/js/sitepulse-dashboard-nav.js†L1-L160】
- **Comparaison pro :** Les suites enterprise regroupent les outils par thématique avec badges d’alertes et autorisations spécifiques.
- **Améliorations proposées :**
  - Définir un schéma de taxonomie (`Performance`, `Sécurité`, `Maintenance`) et générer les sections côté PHP pour offrir des regroupements repliables.
  - Afficher des compteurs d’alertes directement sur les onglets (ex. incidents actifs depuis l’Uptime Tracker) via les données déjà disponibles dans le payload du dashboard.【F:sitepulse_FR/modules/custom_dashboards.php†L1418-L1499】
  - Étendre le stockage local existant pour mémoriser la dernière section consultée et la restituer à la prochaine session.

### 3. Fiabilité & pilotage opérationnel
- **Constat :** Les traitements asynchrones exposent statut, progression et logs détaillés via un composant accessible, mais sans ETA ni actions directes pour relancer/suspendre.【F:sitepulse_FR/includes/admin-settings.php†L3570-L3634】
- **Comparaison pro :** Datadog Jobs Monitor et Better Stack fournissent des ETA calculées, des boutons d’escalade et des exports automatiques.
- **Améliorations proposées :**
  - Calculer les durées moyennes à partir des logs (`relative`) pour afficher une ETA dynamique et ajuster l’intervalle de polling selon la criticité.
  - Ajouter des boutons « Relancer », « Suspendre », « Escalader » déclenchant des hooks (Slack, PagerDuty) et mettant à jour l’état via AJAX.
  - Planifier un export CSV/PDF récurrent des jobs échoués et de leur fréquence pour renforcer la transparence vis-à-vis des clients enterprise.

### 4. Design & visualisations
- **Constat :** Le design system repose déjà sur des variables (`--sitepulse-color-*`) mais les modules clés (AI Insights, Speed Analyzer, Resource Monitor) affichent surtout des tableaux et notices textuelles.【F:sitepulse_FR/modules/css/custom-dashboard.css†L1-L160】【F:sitepulse_FR/modules/ai_insights.php†L2395-L2415】【F:sitepulse_FR/modules/speed_analyzer.php†L1991-L2059】【F:sitepulse_FR/modules/resource_monitor.php†L708-L759】
- **Comparaison pro :** Les solutions premium multiplient gauges, heatmaps et timelines interactives, avec un mode sombre explicite.
- **Améliorations proposées :**
  - Introduire des composants graphiques réutilisables (jauges semi-circulaires pour le Resource Monitor, histogrammes pour l’AI Insights) basés sur les datasets déjà fournis côté PHP.【F:sitepulse_FR/modules/resource_monitor.php†L1500-L1637】
  - Ajouter un toggler clair/sombre persistant (option utilisateur) et harmoniser l’iconographie (Feather/SF Symbols) pour moderniser l’interface.
  - Créer un kit UI documenté (tokens + composants) afin d’aligner les modules et de faciliter les itérations futures.

Ces actions rapprocheraient SitePulse des standards des suites professionnelles tout en capitalisant sur les structures et données déjà présentes dans le code.
