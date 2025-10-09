# SitePulse – comparatif vs suites professionnelles

## Synthèse rapide
- **Couverture fonctionnelle** : l’extension couvre la vitesse, l’uptime, la base de données, l’IA et les alertes, mais chaque module reste piloté par quelques options globales (seuils, fréquences) sans profils multi-site ni workflows automatisés dignes des plateformes MSP/APM.
- **Expérience d’administration** : le tableau de bord et les réglages respectent la charte WP avec navigation horizontale et panneau de préférences, mais l’utilisateur n’a ni vue d’ensemble croisée, ni scénarios guidés pour prioriser les incidents.
- **Gutenberg / front-end** : le bloc « Aperçu du tableau de bord » restitue fidèlement les cartes avec résumé textuel accessible, toutefois il n’expose pas de variations de thème ou de données simulées pour l’éditeur visuel.

## 1. Options, observabilité et automatisation
### État actuel
- Les réglages centralisent l’activation des modules, la clé Gemini, les destinataires d’alertes, les seuils vitesse/uptime et quelques fenêtres de maintenance.【F:sitepulse_FR/includes/admin-settings.php†L1745-L2056】
- Les seuils de performance ne gèrent que deux niveaux (avertissement/critique) par profil, ce qui limite la granularité par rapport aux suites qui surveillent LCP, INP ou CLS séparément.【F:sitepulse_FR/includes/functions.php†L210-L275】
- La rétention d’uptime est désormais configurable entre 30 et 365 jours (`sitepulse_get_uptime_history_retention_days()`), mais aucun rapport SLA consolidé n’est généré automatiquement pour capitaliser sur ces fenêtres étendues.【F:sitepulse_FR/modules/uptime_tracker.php†L1004-L1028】
- Le plugin sait charger plusieurs agents (`SITEPULSE_OPTION_UPTIME_AGENTS`), normaliser la file d’attente distante (TTL filtrable, limite configurable, déduplication) et orchestrer les jobs, toutefois l’interface d’administration ne propose pas encore de gestion CRUD avancée (ajout/suspension d’agents, pondération) ni de métriques associées.【F:sitepulse_FR/modules/uptime_tracker.php†L277-L346】【F:sitepulse_FR/modules/uptime_tracker.php†L706-L836】
- Les analyses IA sont planifiées via un unique événement WP-Cron et, en cas d’échec de planification, exécutées immédiatement sans file d’attente priorisée ni reprise automatique des erreurs.【F:sitepulse_FR/modules/ai_insights.php†L1769-L1890】

### Pistes d’alignement « pro »
- Introduire des **profils de seuils multiplateforme** (Core Web Vitals, budgets par type de page, segmentation mobile/desktop) et permettre des politiques par environnement (prod/preprod) pour refléter les pratiques d’APM.
- Étendre l’**historisation** : stockage sur 90/365 jours, exports CSV/REST et graphiques SLA pour rapprocher l’outil de Pingdom/Better Uptime.
- Offrir une **gestion multi-agents** (ajout/suspension, pondération géographique) avec corrélation automatique aux fenêtres de maintenance pour préparer des rapports régionaux.
- Mettre en place une **file d’attente de jobs IA** (Action Scheduler, priorisation par criticité, reprise exponentielle) et journaliser les coûts/quota par requête.

## 2. UX/UI du back-office
### Points forts identifiés
- Le tableau de bord propose une navigation horizontale avec fallback mobile `<select>` et des liens décorés (`aria-current`) qui respectent la charte WP.【F:sitepulse_FR/modules/custom_dashboards.php†L2673-L2746】
- Un panneau de préférences accessible (focus conservé, `wp.a11y.speak`) permet de masquer/redimensionner les cartes via glisser-déposer.【F:sitepulse_FR/modules/js/sitepulse-dashboard-preferences.js†L10-L307】【F:sitepulse_FR/modules/js/sitepulse-dashboard-preferences.js†L283-L391】
- La navigation JavaScript tient compte de `prefers-reduced-motion` et évite les scrolls intempestifs.【F:sitepulse_FR/modules/js/sitepulse-dashboard-nav.js†L4-L109】

### Axes d’amélioration
- Ajouter une **vue synthétique** (SLA global, incidents ouverts, dettes de maintenance) avant les cartes pour guider la priorisation, à la manière des suites pro qui affichent des KPI “hero”.
- Proposer des **filtres transverses** (par site, par agent, par statut) et un moteur de recherche contextualisé, absents de l’interface actuelle, pour éviter les longs scrolls.
- Enrichir les cartes avec des **indicateurs de tendance** (sparklines, écarts vs objectif) et des liens d’action rapide (ouvrir un ticket, lancer un scan) directement visibles.

## 3. Navigation mobile et responsive
### Constats
- Sous 782 px, la navigation horizontale est remplacée par un formulaire `<select>` tandis que la grille conserve des cartes de 260 px minimum, pouvant produire de longues colonnes sur smartphone.【F:sitepulse_FR/modules/css/custom-dashboard.css†L1-L205】

### Recommandations
- Ajouter un **sommaire collant** ou une barre d’actions fixe sur mobile pour éviter les retours en haut de page après chaque module.
- Prévoir des **gabarits de cartes “compactes”** (<260 px) et des regroupements par accordéon pour limiter le scroll vertical.
- Intégrer des **gestes rapides** (boutons flottants “scanner”, “mettre en pause les alertes”) qui restent accessibles au pouce.

## 4. Accessibilité
### Bonnes pratiques déjà présentes
- Les cartes masquées reçoivent bien `hidden`/`aria-hidden` et les annonces vocales confirment l’enregistrement des préférences.【F:sitepulse_FR/modules/js/sitepulse-dashboard-preferences.js†L224-L307】
- Le bloc Gutenberg génère un résumé textuel, attribue `role="img"` au canvas et ajoute un fallback pour lecteurs d’écran.【F:sitepulse_FR/blocks/dashboard-preview/render.php†L23-L168】

### Compléments suggérés
- Ajouter des **indicateurs de focus visibles** personnalisés sur les cartes et boutons d’action (actuellement dépendants des styles WP par défaut) et documenter un thème à contraste renforcé.
- Prévoir une **option d’alternative textuelle complète** pour les graphiques (tableaux exportables, titres `aria-live` lors de mises à jour) et vérifier la navigation clavier dans les listes déroulantes.
- Auditer la **hiérarchie de titres** (`h1/h2/h3`) et l’ordre de tabulation dans les formulaires de réglages pour garantir une lecture cohérente via lecteurs d’écran.

## 5. Apparence WordPress & éditeur visuel
### Observations
- Le bloc `wp-block-sitepulse-dashboard-preview` reprend la grille des cartes et offre des densités (`comfortable`, `compact`, `spacious`) mais reste limité aux couleurs de l’admin WP.【F:sitepulse_FR/blocks/dashboard-preview/style.css†L1-L169】
- Les attributs permettent d’afficher/masquer les cartes principales (vitesse, uptime, base, logs) sans prévisualisation de données fictives en mode éditeur.【F:sitepulse_FR/blocks/dashboard-preview/render.php†L204-L259】

### Opportunités
- Fournir des **variations de style** (mode sombre, alignwide harmonisé avec le thème public) et des options de typographie pour s’intégrer aux chartes marketing.
- Afficher dans l’éditeur une **simulation de données** (états “ok/alerte”) ou des messages pédagogiques afin que les auteurs visualisent l’impact sans avoir à déclencher un scan réel.
- Ajouter des **contrôles Gutenberg additionnels** (ordre des cartes, choix d’icônes, CTA vers le back-office) pour offrir une expérience proche des widgets SaaS embarqués.

## 6. Intégrations & API
### État actuel
- Les routes REST existantes orchestrent surtout les process internes (queue uptime, tests d’alertes) et reposent sur l’authentification WordPress classique.【F:sitepulse_FR/modules/uptime_tracker.php†L112-L168】【F:sitepulse_FR/modules/error_alerts.php†L1430-L1458】
- Le suivi uptime persiste désormais jusqu’à 365 jours d’historique selon la configuration, mais aucune API REST n’expose encore ces séries longues pour alimenter des dashboards externes (Grafana, Better Uptime).【F:sitepulse_FR/modules/uptime_tracker.php†L1004-L1028】
- Le module Resource Monitor stocke des snapshots sur une fenêtre d’environ 24 h (TTL `DAY_IN_SECONDS`) et 288 points maximum, sans mécanisme d’export ni de normalisation vers des standards type OpenTelemetry.【F:sitepulse_FR/modules/resource_monitor.php†L827-L845】【F:sitepulse_FR/modules/resource_monitor.php†L998-L1023】

### Recommandations « pro »
- Étendre le catalogue REST (ou GraphQL) pour exposer **toutes les métriques clé** (uptime, ressources, vitesse, alertes) avec gestion d’authentification applicative (tokens, OAuth) afin de faciliter l’intégration Grafana/Datadog.
- Prévoir des **connecteurs d’export** (CSV programmables, webhooks, diffusions temps réel) et des webhooks entrants pour rapprocher SitePulse des suites MSP.
- Normaliser les métriques (nomenclature, unités, statut) et permettre un **mode push** vers des bus d’événements (AWS EventBridge, Kafka) pour la corrélation cross-outils.

## 7. Fiabilité & montée en charge
### Constat
- Les jobs IA sont planifiés via `wp_schedule_single_event` ; en cas d’échec du cron, le traitement bascule en synchrone, ce qui garantit un résultat mais sans stratégie de reprise ni priorisation multi-jobs.【F:sitepulse_FR/modules/ai_insights.php†L1769-L1805】
- L’uptime monitor fournit une orchestration REST et WP-CLI mais n’installe qu’un seul agent par défaut, limitant la résilience régionale.【F:sitepulse_FR/modules/uptime_tracker.php†L112-L168】
- Les historiques condensés (30 checkpoints uptime, ~24 h de ressources) compliquent l’analyse post-mortem et la corrélation avec des incidents rares.

### Pistes d’amélioration
- Ajouter une **file de traitement asynchrone** (Action Scheduler, queues Redis, priorités) pour les modules lourds (IA, scans de vitesse) avec journalisation de l’état et des reprises.
- Supporter la **redondance multi-agents** (régions, providers) et une politique de quorum pour les alertes uptime, à l’image des plateformes pro.
- Étendre les historiques et proposer des **rétentions paramétrables** (30/90/365 jours) avec agrégations horaires afin de mieux documenter les SLA et MTTR.

## 8. Reporting, partage et pilotage d’équipe
### État actuel
- Les réglages affichent un tableau de bord synthétique (statut uptime, progression des purges, prochaines étapes) mais ne proposent ni consolidation multi-site ni exports SLA planifiés, là où les suites pro livrent des vues unifiées par portefeuille et des rapports périodiques prêts à envoyer aux clients.【F:sitepulse_FR/includes/admin-settings.php†L1704-L2099】
- L’historique des recommandations IA permet un export ponctuel (CSV et presse-papiers) mais ne propose aucun connecteur vers Jira, Slack ou les outils CRM pour transformer automatiquement ces suggestions en tickets suivis, contrairement aux plateformes MSP qui synchronisent chaque alerte dans un flux de travail partagé.【F:sitepulse_FR/modules/ai_insights.php†L2260-L2330】
- La normalisation des jobs asynchrones ne gère que deux types d’opérations (purge de transients, reset) et n’affiche que les cinq derniers logs, empêchant les revues complètes et la traçabilité multi-équipes observées dans les consoles d’orchestration professionnelles.【F:sitepulse_FR/includes/functions.php†L1120-L1279】

### Écarts face aux suites professionnelles
- **Rapports automatisés** : Pingdom, Better Stack ou ManageWP envoient des PDF/CSV brandés, programmables par client et par fuseau horaire. SitePulse nécessite encore une extraction manuelle module par module.
- **Collaboration & ticketing** : les suites pro proposent des intégrations Slack/Teams, des connecteurs Jira/Asana et des workflows d’approbation. SitePulse reste centré sur la consultation individuelle au sein de l’admin WordPress.
- **Pilotage d’orchestration** : des solutions comme New Relic ou Datadog exposent timelines, runbooks et audit trail des jobs. Le journal SitePulse se limite à un extrait de cinq entrées sans notions de propriétaire ni de statut agrégé.

### Leviers d’alignement « pro »
- Implémenter des **rapports programmés** (e-mail, PDF/CSV, librairie de modèles) avec branding client, plages horaires et filtres multi-sites, ainsi qu’une API REST/GraphQL pour extraire ces données vers des tableaux de bord externes.
- Proposer des **connecteurs collaboratifs** prêts à l’emploi (webhooks configurables, intégrations Slack/Teams, synchronisation Jira/Linear) capables de convertir recommandations IA, incidents uptime ou alertes ressources en tâches actionnables avec suivi bidirectionnel.
- Étendre l’**orchestrateur de jobs** avec des files dédiées par module, un suivi temps réel (statut, durée, opérateur), la conservation historique et des webhooks post-exécution afin d’aligner la gouvernance sur les consoles MSP/APM.
