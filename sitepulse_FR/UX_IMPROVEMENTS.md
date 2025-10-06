# Recommandations UI/UX face aux standards des suites pro

Ce document compare l'expérience d'administration actuelle de SitePulse à celle d'outils SaaS établis (ex. Datadog, Better Uptime, New Relic) et suggère des améliorations concrètes. Les constats s'appuient sur le code et les feuilles de style existants.

## 1. Tableau de bord principal
- **Constat actuel** : l'écran `/Dashboard` affiche un titre, un paragraphe descriptif et une simple navigation horizontale vers les modules, sans synthèse visuelle immédiate.【F:sitepulse_FR/modules/custom_dashboards.php†L2673-L2746】 Les styles se limitent à des badges clairs/sombres hérités du back-office WordPress.【F:sitepulse_FR/modules/css/custom-dashboard.css†L1-L120】
- **Écart vs apps pro** : Datadog ou Better Uptime ouvrent sur des KPI cards (SLA, incidents ouverts, temps de réponse) et des résumés comparatifs sur 24 h/7 j.
- **Améliorations proposées** :
  - Ajouter une grille de cartes KPI en tête de page (uptime global, erreurs fatales, vitesse moyenne) avec code couleur, tendance et badges « à surveiller » pour un aperçu immédiat.
  - Offrir un sélecteur de période globale (24 h/7 j/30 j) qui pilote les widgets plutôt qu'un simple texte statique.
  - Introduire un bandeau d'état contextualisé (ex. « 2 incidents en cours ») avec CTA vers la résolution, pour se rapprocher des playbooks incident-response.

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
