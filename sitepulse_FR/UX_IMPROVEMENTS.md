# Recommandations UI/UX face aux standards des suites pro

Ce document compare l'expérience d'administration actuelle de SitePulse à celle d'outils SaaS établis (ex. Datadog, Better Uptime, New Relic) et suggère des améliorations concrètes. Les constats s'appuient sur le code et les feuilles de style existants.

## 1. Tableau de bord principal
- **Constat actuel** : l'écran `/Dashboard` affiche un titre, un paragraphe descriptif et une simple navigation horizontale vers les modules, sans synthèse visuelle immédiate.【F:sitepulse_FR/modules/custom_dashboards.php†L2673-L2746】 Les styles se limitent à des badges clairs/sombres hérités du back-office WordPress.【F:sitepulse_FR/modules/css/custom-dashboard.css†L1-L120】
- **Écart vs apps pro** : Datadog ou Better Uptime ouvrent sur des KPI cards (SLA, incidents ouverts, temps de réponse) et des résumés comparatifs sur 24 h/7 j.
- **Améliorations proposées** :
  - **Grille de KPI en tête de page** :
    - Implémenter un conteneur `grid` sur 3 colonnes desktop / 1 colonne mobile juste après `<section class="sitepulse-dashboard">` avec des cartes composées d'un titre, d'une valeur principale, d'une jauge miniature (sparkline SVG issue des historiques existants) et d'un badge d'alerte calculé côté PHP (`class="status--warning"` quand le seuil SLA < 99,5 %, `status--danger` si erreurs fatales > 5).【F:sitepulse_FR/modules/custom_dashboards.php†L2265-L2430】
    - Réutiliser les agrégations existantes : `sitepulse_calculate_uptime_window_metrics()` pour l'uptime (7 j / 30 j), les compteurs de logs construits dans `$logs_card['counts']` pour les erreurs fatales, et la dernière mesure de `server_processing_ms` issue du module Speed Analyzer pour la vitesse.【F:sitepulse_FR/modules/uptime_tracker.php†L1465-L1603】【F:sitepulse_FR/modules/custom_dashboards.php†L1662-L1753】【F:sitepulse_FR/modules/custom_dashboards.php†L1310-L1436】
    - Ajouter une sous-ligne de tendance (icône flèche + pourcentage) calculée en PHP via la comparaison des métriques période actuelle / précédente en s'appuyant sur les archives déjà récupérées (`$history_entries`, `$uptime_archive`). Les classes `trend--up|down|flat` colorent la flèche (vert, rouge, gris) et sont alignées sur les status badges déjà définis (`status-ok|warn|bad`).【F:sitepulse_FR/modules/custom_dashboards.php†L1336-L1406】【F:sitepulse_FR/modules/uptime_tracker.php†L1465-L1488】
  - **Sélecteur de période global** :
    - Remplacer le libellé statique « Données sur 7 derniers jours » par un composant `<div class="range-picker">` regroupant trois boutons radio (24 h, 7 j, 30 j) et un `<select>` fallback mobile, synchronisés via `data-range`.
    - Centraliser la valeur sélectionnée dans `localStorage` (clé `sitepulseRange`) et dans une option WordPress pour persistance multi-session. Le JS `sitepulse-dashboard-nav.js` doit émettre un événement personnalisé `sitepulse:rangeChange` à chaque sélection afin que les widgets écoutent l'événement et rafraîchissent leurs requêtes AJAX avec le paramètre `range`.
    - Étendre l'API REST déjà initialisée par les modules (`register_rest_route('sitepulse/v1', …)` dans Uptime Tracker et Error Alerts) avec un nouvel endpoint `metrics` acceptant `range`. Les callbacks peuvent réutiliser les helpers existants qui lisent `SITEPULSE_OPTION_UPTIME_LOG` et les historiques de vitesse/logs pour renvoyer des données synchronisées à la grille et aux widgets.【F:sitepulse_FR/modules/uptime_tracker.php†L136-L205】【F:sitepulse_FR/modules/error_alerts.php†L1432-L1499】
  - **Bandeau d'état contextualisé** :
    - Insérer un composant `alert-banner` plein largeur au-dessus de la grille KPI, affichant dynamiquement le nombre d'incidents actifs en s'appuyant sur les indicateurs déjà calculés dans le module Uptime (ex. `$current_incident_duration`, `$incident_count`). Exemple : « 🚨 2 incidents en cours sur la région EU-West » qui se met à jour via Ajax lors des rafraîchissements du log.【F:sitepulse_FR/modules/uptime_tracker.php†L1417-L1994】
    - Ajouter un bouton primaire « Voir le playbook » qui redirige vers la page d'incident management ou ouvre un drawer avec les actions recommandées (checklist issue du module concerné). Le bouton porte l'attribut `data-cta="incident-playbook"` pour faciliter le suivi analytique.
    - Prévoir trois états visuels : `info` (aucun incident, texte rassurant + lien vers documentation), `warning` (1 incident non critique) et `danger` (>1 incident critique). Les couleurs reprennent la palette du design system et s'accompagnent d'une icône SF Symbols/Feather pour renforcer la lecture. Un `aria-live="polite"` permet d'annoncer vocalement les changements lors des mises à jour Ajax.

## 2. Navigation inter-modules
- **Constat actuel** : la navigation horizontale repose sur un slider manuel avec boutons précédent/suivant et un select mobile, sans recherche ni regroupement par persona.【F:sitepulse_FR/modules/custom_dashboards.php†L2677-L2746】
- **Écart vs apps pro** : Les consoles pro offrent des hubs modulaires avec catégories (Performance, Sécurité, Maintenance) et recherche instantanée (typeahead) pour réduire la charge cognitive.
- **Améliorations proposées** :
  - Ajouter un champ de recherche dans la barre de navigation pour filtrer les modules par nom ou tag.
  - Regrouper les items en sections repliables (« Performance », « Observabilité », « Maintenance ») avec des séparateurs visuels et des icônes thématiques.
  - Afficher des badges d'état (ex. alertes actives) directement sur les onglets afin de prioriser les actions, à l'image de l'interface Pingdom.

## 3. Cohérence visuelle et mode sombre
- **Constat actuel** : chaque module applique sa propre carte blanche/grise, avec quelques variantes `:is-dark` et `prefers-color-scheme`, mais sans thème unifié ni gestion centralisée de la palette.【F:sitepulse_FR/modules/css/custom-dashboard.css†L1-L120】【F:sitepulse_FR/modules/css/ai-insights.css†L30-L131】
- **Écart vs apps pro** : Les suites premium utilisent un design system (couleurs primaires, secondaires, typographie) et une bascule explicite clair/sombre.
- **Améliorations proposées** :
  - Définir un tokens design (variables CSS pour couleurs, ombres, rayons) injectés globalement et utilisés dans tous les modules pour éviter les divergences.
  - Ajouter un toggler UI clair/sombre dans la barre supérieure avec mémorisation utilisateur (option/meta) plutôt que de s'en remettre uniquement aux media queries.
  - Harmoniser la typographie (taille, graisse) et les espacements pour que les cartes partagent une hiérarchie visuelle cohérente.

## 4. Module « Analyses par IA »
- **Constat actuel** : l'écran juxtapose un bloc d'informations, un bouton primaire et une liste historique, sans prévisualisation de l'impact ni visualisation graphique.【F:sitepulse_FR/modules/ai_insights.php†L2357-L2515】【F:sitepulse_FR/modules/css/ai-insights.css†L14-L131】
- **Écart vs apps pro** : Des produits comme ContentKing ou Surfer SEO mettent en avant le gain estimé, des statuts (En cours, Terminé) et des timelines avec filtres avancés.
- **Améliorations proposées** :
  - Introduire des cartes « Insight récent » avec score d'impact, estimation de temps et boutons d'action (Marquer comme fait, Créer un ticket) directement depuis la vue.
  - Ajouter des visualisations (mini sparklines de performance avant/après, histogramme des recommandations par catégorie) pour mieux raconter la donnée.
  - Enrichir l'historique d'une timeline verticale paginée avec tags (performance, sécurité) et filtres persistants, ainsi qu'une recherche plein texte sur les recommandations.

## 5. Accessibilité et micro-interactions
- **Constat actuel** : certaines sections reposent sur des paragraphes masqués (`display:none`) révélés en JS sans animation ou feedback vocal, et les boutons n'ont pas d'états « chargement » dédiés en dehors du spinner générique.【F:sitepulse_FR/modules/ai_insights.php†L2398-L2412】【F:sitepulse_FR/modules/js/sitepulse-dashboard-nav.js†L1-L78】
- **Écart vs apps pro** : Les applications pro incluent des skeletons, ARIA live bien annoncés, et des transitions discrètes pour renforcer la perception de réactivité.
- **Améliorations proposées** :
  - Ajouter des placeholders skeleton pour les cartes en chargement et des annonces ARIA (`aria-live="assertive"`) lorsque les données sont disponibles.
  - Généraliser les boutons avec état (`aria-pressed`, `data-loading`) et textes d'aide (tooltips) pour les actions destructives/sensibles.
  - Prévoir des transitions micro-interactives (fade-in/out, survol) respectant `prefers-reduced-motion` mais apportant du feedback visuel aux utilisateurs.

## 6. Personnalisation et partage
- **Constat actuel** : la personnalisation du dashboard se limite aux préférences stockées localement et ne propose ni partage ni presets par persona.【F:sitepulse_FR/modules/custom_dashboards.php†L2749-L2760】
- **Écart vs apps pro** : Datadog ou Looker permettent de publier des vues partagées, cloner des dashboards et appliquer des layouts prédéfinis.
- **Améliorations proposées** :
  - Ajouter des « templates de dashboard » (Ops, SEO, e-commerce) sélectionnables lors de la création.
  - Permettre l'enregistrement de vues partagées (lecture seule) et la génération de liens publics ou exports PDF.
  - Introduire une bibliothèque de widgets avec prévisualisation et recommandations contextuelles selon les données disponibles.

Ces évolutions rapprocheraient l'expérience SitePulse des standards premium tout en conservant la compatibilité avec le back-office WordPress.
